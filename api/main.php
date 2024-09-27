<?php
// Database connection
$dsn = 'mysql:host=localhost;dbname=lrjudext_SKMegaForwarder;charset=utf8mb4';
$username = 'lrjudext_SKMegaForwarder';
$password = 'SKMegaForwarder';
$pdo = new PDO($dsn, $username, $password);

// Define the bot token
function GetBotData()
{
    global $pdo;

    $sql = "SELECT * FROM botconfig LIMIT 1"; // Fetch only one row
    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function setBotCommands()
{
    $commands = [
        ['command' => 'start', 'description' => 'Start the bot'],
        ['command' => 'help', 'description' => 'Get help and instructions'],
        ['command' => 'settings', 'description' => 'Update your settings'],
        ['command' => 'feedback', 'description' => 'Send feedback'],
    ];

    sendTelegramRequest('setMyCommands', [
        'commands' => json_encode($commands)
    ]);
}
function setPersistentMenu($chat_id)
{
    // Define menu options
    $menu_button = [
        "type" => "commands", // The type of the button
    ];

    // Send the request to set the persistent menu
    sendTelegramRequest('setChatMenuButton', [
        'chat_id' => $chat_id,   // The chat ID
        'menu_button' => $menu_button  // Menu structure
    ]);
}
// Retrieve config data
$data = GetBotData();
$bot_token = $data['bot_token'];
$botOwnerId = $data['owner_chat_id'];
define('BOT_TOKEN', $bot_token);

// Get the request from Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Function to make API calls to Telegram
function sendTelegramRequest($method, $params = [])
{
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
function registerUser($telegram_user_id, $user_name)
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO users (telegram_user_id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = ?");
    $stmt->execute([$telegram_user_id, $user_name, $user_name]);
}
// Handle message or callback queries
if (isset($update['message'])) {
    $message = $update['message'];
    $message_id = $message['message_id'];
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $user_name = $message['from']['first_name'];

    // Handle incoming message
    handleMessage($message, $chat_id, $user_id, $user_name, $message_id);
} elseif (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    handleCallbackQuery($callbackQuery);
}
function DeleteMe($chat_id, $message_id)
{
    sendTelegramRequest('deleteMessage', [
        'chat_id' => $chat_id, // Chat ID where the message was sent
        'message_id' => $message_id // The /start message ID
    ]);
}
// Function to handle incoming messages
function handleMessage($message, $chat_id, $user_id, $user_name, $message_id)
{
    global $botOwnerId;

    // Help command for the owner (admin)
    if ($message['text'] === '/start') {
        setBotCommands();
        setPersistentMenu($chat_id);
        DeleteMe($chat_id, $message_id);
        startMessage($user_name, $chat_id);
        return;
    }

    // Help command for the owner (admin)
    if ($message['text'] === '/help' && $user_id == $botOwnerId) {
        DeleteMe($chat_id, $message_id);
        sendOwnerHelpMessage($chat_id);
        return;
    }

    // Handle ADMIN COMMANDS
    if (strpos($message['text'], '/') === 0) {
        $command = explode(' ', $message['text'])[0];
        DeleteMe($chat_id, $message_id);
        switch ($command) {
            case '/add_channel':
                addChannelCommand($chat_id, $message['text']);
                break;
            case '/view_users':
                viewUsersCommand($chat_id);
                break;
            case '/view_premium':
                viewPremiumUsersCommand($chat_id);
                break;
        }
    }

    // Handle forwarded messages (from channels)
    if (isset($message['forward_from_chat'])) {
        handleForwardedMessage($message, $chat_id, $user_id, $user_name);
    }
}
function isBotAdmin($channel_id)
{
    global $bot_token; // Make sure your bot token is accessible

    // Make a request to get the bot's status in the channel
    $response = sendTelegramRequest('getChatMember', [
        'chat_id' => $channel_id,
        'user_id' => $bot_token // Use the bot's ID
    ]);
    $response = json_decode($response, true);
    // Check if the response is valid and if the bot is an admin
    if (isset($response['result'])) {
        return $response['result']['status'] === 'administrator' || $response['result']['status'] === 'creator';
    }

    return false; // Bot is not an admin or an error occurred
}
function sendConfHint($chat_id, $message_id = null)
{
    global $botOwnerId;
    // If a message ID is provided, delete the previous message
    if ($message_id !== null) {
        sendTelegramRequest('deleteMessage', [
            'chat_id' => $chat_id, // Chat ID where the message was sent
            'message_id' => $message_id // The /start message ID to delete
        ]);
    }

    // Create an inline keyboard with buttons for adding host channels, forwarding channels, and viewing user config
    $inline_keyboard = [
        [
            ['text' => '🏠 Add Host Channel', 'callback_data' => 'add_host'],
            ['text' => '🔀 Add Forwarding Channels', 'callback_data' => 'add_forwarding']
        ],
        [
            ['text' => '📑 View Configuration', 'callback_data' => 'view_config']
        ]
    ];

    // Send the message with the inline buttons
    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "⚙️ *Welcome to Your Bot Control Panel!*\n\n" .
            "👋 *Hey there!* You're just a few steps away from setting up your forwarding bot. Here's what you can do:\n\n" .
            "🏠 *Add a Host Channel*: \n" .
            "   Select the main channel from which messages will be forwarded.\n\n" .
            "🔀 *Add Forwarding Channels*: \n" .
            "   Choose the channels where the messages will be sent to.\n\n" .
            "📑 *View Configuration*: \n" .
            "   Check your current setup and make changes if needed!\n\n" .
            "💡 *Plan Overview:*\n" .
            "   - *Free Plan*: *1 Host Channel* & Up to *5 Forwarding Channels*.\n" .
            "   - *Premium Plan*: *2 Host Channels* & Up to *10 Forwarding Channels*.\n\n" .
            "*Need help?* Contact the owner: [Contact Owner](tg://user?id=$botOwnerId)\n\n",
        "⏩ *Go ahead and start configuring your bot now!*",
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => $inline_keyboard
        ])
    ]);
}
function startMessage($user_name, $chat_id)
{
    $data = GetBotData();
    $channels = json_decode($data['channels'], true); // Decode JSON to array

    // Build the inline keyboard
    $inline_keyboard = [];
    $joined = true; // Assume user has joined all channels initially

    foreach ($channels as $channel) {
        // Check if user has joined the channel
        if (!hasUserJoinedChannel($chat_id, '@' . ltrim($channel['link'], 'https://t.me/'))) {
            $joined = false; // User hasn't joined at least one channel
            $text = $channel['text'];
            $link = $channel['link'];
            $inline_keyboard[] = [
                [
                    'text' => $text,
                    'url' => $link
                ]
            ];
        }
    }

    if ($joined) {
        // User is already registered and has joined all channels
        $response = sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "👋 *Welcome back, $user_name!* \n\n✨ You can continue enjoying our services and stay updated with the latest messages!",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $inline_keyboard
            ])
        ]);
        $message_id = null;
        if (isset($response['result']['message_id'])) {
            $message_id = $response['result']['message_id'];
        }
        sendConfHint($chat_id, $message_id);
        return; // Exit to prevent sending another message
    }

    // If not all channels are joined, add a button to check membership status
    $inline_keyboard[] = [
        [
            'text' => '✅ ᴊᴏɪɴᴇᴅ ☑️',
            'callback_data' => 'check_membership'
        ]
    ];

    // Send welcome message with inline buttons
    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "👋 *Welcome to the Bot, $user_name!* \n\n🔔 Before we begin, please join our Updates Channel.",
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => $inline_keyboard
        ])
    ]);
}
function checkMembershipStatus($chat_id, $message_id)
{
    $data = GetBotData();
    $channels = json_decode($data['channels'], true); // Decode JSON to array
    $not_joined = [];

    foreach ($channels as $channel) {
        $link = $channel['link'];
        $channel_username = '@' . substr($link, strpos($link, 't.me/') + 5); // Extract username and prepend '@'

        if (!hasUserJoinedChannel($chat_id, $channel_username)) {
            $not_joined[] = $channel['text'];
        }
    }

    if (empty($not_joined)) {
        sendTelegramRequest('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "👋 *Welcome ‼️* \n\n✨ You can continue enjoying our services and stay updated with the latest messages‼️",
            'parse_mode' => 'Markdown'
        ]);
        sendConfHint($chat_id, $message_id);
        exit;
    } else {
        // Build the inline keyboard
        $inline_keyboard = [];
        foreach ($channels as $channel) {
            $text = $channel['text'];
            $link = $channel['link'];
            $inline_keyboard[] = [
                [
                    'text' => $text,
                    'url' => $link
                ]
            ];
        }

        // Add a button to check membership status
        $inline_keyboard[] = [
            [
                'text' => '✅ ᴊᴏɪɴᴇᴅ ☑️',
                'callback_data' => 'check_membership'
            ]
        ];

        sendTelegramRequest('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "❌ ʏᴏᴜ ᴀʀᴇ ɴᴏᴛ ᴊᴏɪɴᴇᴅ ɪɴ ᴛʜᴇ ꜰᴏʟʟᴏᴡɪɴɢ ᴄʜᴀɴɴᴇʟꜱ .\n\nPlease join them to continue.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => $inline_keyboard
            ])
        ]);
    }
}
function hasUserJoinedChannel($user_id, $channel_username)
{
    $response = sendTelegramRequest('getChatMember', [
        'chat_id' => $channel_username,
        'user_id' => $user_id
    ]);

    $result = json_decode($response, true);

    if (!isset($result['result']) || $result['result']['status'] == 'left' || $result['result']['status'] == 'kicked') {
        return false;
    }
    return true;
}
function setUserState($user_id, $action)
{
    global $pdo;

    $stmt = $pdo->prepare("
        INSERT INTO user_states (user_id, current_action) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE current_action = ?
    ");

    // If action is null, pass null explicitly
    $stmt->execute([$user_id, $action, $action]);
}

function getUserState($user_id)
{
    global $pdo;
    $stmt = $pdo->prepare("SELECT current_action FROM user_states WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}
function isChannelTaken($channel_id)
{
    global $pdo;

    // Query to check if the channel ID exists in either host_channels or forwarding_channels
    $stmt = $pdo->prepare("
        SELECT telegram_user_id 
        FROM user_configs 
        WHERE JSON_CONTAINS(host_channels, JSON_QUOTE(?)) OR JSON_CONTAINS(forwarding_channels, JSON_QUOTE(?))
    ");
    $stmt->execute([$channel_id, $channel_id]);

    // Fetch the result
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        // Return the Telegram user ID if the channel is taken
        return ['taken' => true, 'user_id' => $result['telegram_user_id']];
    }

    // Channel is available
    return ['taken' => false, 'user_id' => null];
}
function handleForwardedMessage($message, $chat_id, $user_id, $user_name)
{
    global $pdo;
    $chat_forwarded_from = $message['forward_from_chat'];
    $channel_id = $chat_forwarded_from['id'];
    $channel_name = $chat_forwarded_from['title'];
    $current_action = getUserState($user_id);
    // Check if the channel is already taken by another user
    $status = isChannelTaken($channel_id);
    if ($status['taken']) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "<b>❌ This channel is already registered by user ID: <a href='tg://user?id=" . $status['user_id'] . "'>" . $status['user_id'] . "</a></b>",
            'parse_mode' => 'HTML'
        ]);
        return; // Exit the function if the channel is already taken
    }

    // Check if the bot is an admin in the channel
    if (isBotAdmin($channel_id)) {
        // Ask for confirmation
        if ($current_action === 'adding_host') {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ Are you sure you want to add *$channel_name* as a host channel? \n\nPlease confirm by clicking the button below.",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => 'Yes, Add Host', 'callback_data' => "confirm_add_host:$channel_id"]
                        ]
                    ]
                ]),
                'parse_mode' => 'Markdown'
            ]);
        } elseif ($current_action === 'adding_forward') {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ Are you sure you want to add *$channel_name* as a forwarding channel? \n\nPlease confirm by clicking the button below.",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => 'Yes, Add Forward', 'callback_data' => "confirm_add_forward:$channel_id"]
                        ]
                    ]
                ]),
                'parse_mode' => 'Markdown'
            ]);
        }

        return; // Wait for confirmation
    } else {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🚫 *I need to be an admin in that channel to add it.*",
            'parse_mode' => 'Markdown'
        ]);
    }
}
function handleCallbackQuery($callbackQuery)
{
    $chat_id = $callbackQuery['message']['chat']['id'];
    $user_id = $callbackQuery['from']['id'];  // Corrected extraction
    $callback_data = $callbackQuery['data'];

    if ($callback_data === 'add_host') {
        setUserState($user_id, 'adding_host');
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🏠 *Host Channel Setup*\n\n✨ _Let's get your bot up and running! Follow these quick steps:_\n\n💠 *Make the bot an admin* in the channel you wish to use as the host.\n💠 *Forward any message* from that channel _directly to this chat_ to register it.\n\n🔗 _Once you've completed these steps, the bot will automatically forward messages!_\n\n📲 Need help? Feel free to reach out anytime!",
            'parse_mode' => 'Markdown'
        ]);
    } elseif ($callback_data === 'add_forwarding') {
        setUserState($user_id, 'adding_forward');
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🔀 *Forwarding Channel Setup*\n\n🚀 _Let's configure where your messages will be forwarded! Follow these easy steps:_\n\n💠 *Make the bot an admin* in each channel where you want messages forwarded.\n💠 *Forward a message* from _each forwarding channel_ to this chat to register them.\n\n📢 Once set, your messages will be automatically forwarded to the selected channels!",
            'parse_mode' => 'Markdown'
        ]);
    } elseif ($callback_data === 'view_config') {
        viewUserConfig($chat_id);
        setUserState($user_id, null);  // Clear state
    } elseif ($callback_data === 'check_membership') {
        checkMembershipStatus($chat_id, $callbackQuery['message']['message_id']);
    } elseif (strpos($callback_data, 'confirm_add_host:') === 0) {
        $channel_id = str_replace('confirm_add_host:', '', $callback_data);
        // Check if the channel is already taken by another user
        $status = isChannelTaken($channel_id);
        if ($status['taken']) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "<b>❌ This channel is already registered by user ID: <a href='tg://user?id=" . $status['user_id'] . "'>" . $status['user_id'] . "</a></b>",
                'parse_mode' => 'HTML'
            ]);
            return; // Exit the function if the channel is already taken
        }
        addHostChannel($chat_id, $channel_id, $user_id);
        setUserState($user_id, null);  // Clear state
    } elseif (strpos($callback_data, 'confirm_add_forward:') === 0) {
        $channel_id = str_replace('confirm_add_forward:', '', $callback_data);
        // Check if the channel is already taken by another user
        $status = isChannelTaken($channel_id);
        if ($status['taken']) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "<b>❌ This channel is already registered by user ID: <a href='tg://user?id=" . $status['user_id'] . "'>" . $status['user_id'] . "</a></b>",
                'parse_mode' => 'HTML'
            ]);
            return; // Exit the function if the channel is already taken
        }
        addForwardingChannel($chat_id, $channel_id, $user_id);
        setUserState($user_id, null);  // Clear state
    } else if (strpos($callback_data, 'delete_host_') !== false) {
        $index = (int) str_replace('delete_host_', '', $callback_data);
        deleteHostChannel($chat_id, $index);

        // Notify the user
        sendTelegramRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackQuery['id'],
            'text' => "Host Channel " . ($index + 1) . " deleted.",
            'show_alert' => false
        ]);
    } elseif (strpos($callback_data, 'delete_forward_') !== false) {
        // Extract the index from the callback data
        $index = (int) str_replace('delete_forward_', '', $callback_data);
        deleteForwardingChannel($chat_id, $index);

        // Notify the user
        sendTelegramRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackQuery['id'],
            'text' => "Forwarding Channel " . ($index + 1) . " deleted.",
            'show_alert' => false
        ]);
    }
    // Acknowledge the callback query
    sendTelegramRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackQuery['id']
    ]);
}
// Function to delete the channel from the database after confirmation
function deleteHostChannel($telegram_user_id, $index)
{
    // Fetch the user config
    $user_config = getUserConfig($telegram_user_id);

    // Decode the host_channels JSON
    $host_channels = json_decode($user_config['host_channels'], true);

    // Remove the selected host channel
    if (isset($host_channels[$index])) {
        unset($host_channels[$index]);
        // Reindex the array to maintain proper ordering
        $host_channels = array_values($host_channels);
    }

    // Update the database with the new list of host channels
    updateDatabase($telegram_user_id, 'host_channels', json_encode($host_channels));
}
function deleteForwardingChannel($telegram_user_id, $index)
{
    // Fetch the user config
    $user_config = getUserConfig($telegram_user_id);

    // Decode the forwarding_channels JSON
    $forwarding_channels = json_decode($user_config['forwarding_channels'], true);

    // Remove the selected forwarding channel
    if (isset($forwarding_channels[$index])) {
        unset($forwarding_channels[$index]);
        // Reindex the array to maintain proper ordering
        $forwarding_channels = array_values($forwarding_channels);
    }

    // Update the database with the new list of forwarding channels
    updateDatabase($telegram_user_id, 'forwarding_channels', json_encode($forwarding_channels));
}

// Function to update the database
function updateDatabase($telegram_user_id, $column, $value)
{
    global $pdo; // Assuming you're using PDO to connect to your database

    $sql = "UPDATE user_configs SET $column = :value WHERE telegram_user_id = :telegram_user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':value' => $value,
        ':telegram_user_id' => $telegram_user_id
    ]);
}



function getChannelInfo($channel_id)
{
    // Use the Telegram API to get channel info
    $response = sendTelegramRequest('getChat', ['chat_id' => $channel_id]);
    $response = json_decode($response, true);
    if ($response['ok']) {
        return [
            'name' => $response['result']['title'] ?? 'Unknown Channel',
            'link' => $response['result']['invite_link'] ?? null
        ];
    }

    return null; // Return null if the request fails
}

function viewUserConfig($chat_id)
{
    global $pdo, $botOwnerId;

    // Fetch the user configuration from the database
    $stmt = $pdo->prepare("SELECT host_channels, forwarding_channels FROM user_configs WHERE telegram_user_id = ?");
    $stmt->execute([$chat_id]);

    if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $host_channels = json_decode($user['host_channels'], true);
        $forwarding_channels = json_decode($user['forwarding_channels'], true);

        // Initialize configuration text
        $configText = "✨ <b>Your Forwarding Bot Configuration</b> ✨\n\n";

        // Host Channels Section
        if (!empty($host_channels)) {
            $configText .= "🎖 <b>Host Channels:</b>\n\n";
            foreach ($host_channels as $index => $host_channel) {
                $channel_info = getChannelInfo($host_channel);
                if ($channel_info) {
                    $channel_name = $channel_info['name'];
                    $channel_link = !empty($channel_info['link']) ? $channel_info['link'] : null;
                    if ($channel_link) {
                        $configText .= ($index + 1) . "️⃣ <b><a href=\"$channel_link\">$channel_name</a></b>\n";
                    } else {
                        $configText .= ($index + 1) . "️⃣ <b>$channel_name</b> 🔒 Private (No invite link available)\n";
                    }
                } else {
                    $configText .= ($index + 1) . "️⃣ 🔒 Private (No invite link available)\n";
                }
            }
        } else {
            $configText .= "🚫 <b>No Host Channels Added Yet.</b>\n";
        }

        // Separator
        $configText .= "\n=====================\n\n";

        // Forwarding Channels Section
        if (!empty($forwarding_channels)) {
            $configText .= "📩 <b>Forwarding Channels:</b>\n\n";
            foreach ($forwarding_channels as $index => $forward_channel) {
                $channel_info = getChannelInfo($forward_channel);
                if ($channel_info) {
                    $channel_name = $channel_info['name'];
                    $channel_link = !empty($channel_info['link']) ? $channel_info['link'] : null;
                    if ($channel_link) {
                        $configText .= ($index + 1) . "⃣ <b><a href=\"$channel_link\">$channel_name</a></b>\n";
                    } else {
                        $configText .= ($index + 1) . "⃣ <b>$channel_name</b> 🔒 Private (No invite link available)\n";
                    }
                }
            }
        } else {
            $configText .= "🚫 <b>No Forwarding Channels Added Yet.</b>\n";
        }

        // Add an encouragement message
        $configText .= "\n🎯 <b>You’re all set!</b> Add more channels or update your configuration as needed.\n";

        // Add Contact Owner Button
        $configText .= "\n💬 <b>Need help?</b> <a href=\"tg://user?id=$botOwnerId\">Contact Support</a>";

        // Prepare inline buttons for deletion
        $inline_buttons = [];

        // Add delete buttons for host channels
        if (!empty($host_channels)) {
            foreach ($host_channels as $index => $host_channel) {
                $inline_buttons[] = [
                    [
                        'text' => 'Delete Host ' . ($index + 1),
                        'callback_data' => "delete_host_$index"
                    ]
                ];
            }
        }

        // Add delete buttons for forwarding channels
        if (!empty($forwarding_channels)) {
            foreach ($forwarding_channels as $index => $forward_channel) {
                $inline_buttons[] = [
                    [
                        'text' => 'Delete Forward ' . ($index + 1),
                        'callback_data' => "delete_forward_$index"
                    ]
                ];
            }
        }

        // Prepare the inline keyboard
        $reply_markup = json_encode([
            'inline_keyboard' => $inline_buttons
        ]);

        // Send the configuration as a message with inline buttons
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $configText,
            'disable_web_page_preview' => true, // Disable link preview
            'parse_mode' => 'HTML', // Use HTML parsing
            'reply_markup' => $reply_markup // Attach the inline keyboard
        ]);
    } else {
        // If no configuration is found
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "*❌ ɴᴏ ᴄᴏɴꜰɪɢᴜʀᴀᴛɪᴏɴ ꜰᴏᴜɴᴅ ꜰᴏʀ ʏᴏᴜʀ ᴀᴄᴄᴏᴜɴᴛ. ᴘʟᴇᴀꜱᴇ ꜱᴇᴛ ɪᴛ ᴜᴘ ꜰɪʀꜱᴛ*",
            'parse_mode' => 'Markdown'
        ]);
    }
}
function getUserData($user_id)
{
    global $pdo; // Use your PDO instance

    // Prepare a SQL statement to fetch user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_user_id = ?");
    $stmt->execute([$user_id]);

    // Fetch the user data
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ? $user : null; // Return user data or null if not found
}
function sendOwnerHelpMessage($chat_id)
{
    $helpText = "👑 *Owner Help Menu*\n\n" .
        "Here are the commands available to manage the bot:\n\n" .
        "`/add_channel (text)[link]` *- Set the updates channel*\n\n" .
        "`/view_users` *- View all registered users*\n\n" .
        "`/view_premium` *- Restart the bot*\n\n";

    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $helpText,
        'parse_mode' => 'Markdown'
    ]);
}
function addChannelCommand($chat_id, $text)
{
    global $pdo;

    // Parse the command for text and link
    preg_match('/\/add_channel\s+(.+)\[(.+)\]/', $text, $matches);
    if (count($matches) === 3) {
        $description = $matches[1];
        $link = $matches[2];

        // Get the existing bot config
        $stmt = $pdo->prepare("SELECT channels FROM botconfig LIMIT 1");
        $stmt->execute();
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        // Decode the existing channels JSON
        $channels = json_decode($config['channels'], true);

        // Add new channel to the array
        $channels[] = [
            'text' => $description,
            'link' => $link
        ];

        // Encode the updated channels back to JSON
        $updatedChannels = json_encode($channels);

        // Update the botconfig with the new channels
        $updateStmt = $pdo->prepare("UPDATE botconfig SET channels = ? WHERE id = 1");
        $updateStmt->execute([$updatedChannels]);

        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "*✅ 🅲🅷🅰🅽🅽🅴🅻 🅰🅳🅳🅴🅳*\n\n Name: *$description*\n\nLink: *$link*",
            'parse_mode' => 'Markdown'
        ]);
    } else {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Invalid format. Use: `/add_channel (description)[link]`. ",
            'parse_mode' => 'Markdown'
        ]);
    }
}
function viewUsersCommand($chat_id)
{
    global $pdo;

    $stmt = $pdo->query("SELECT telegram_user_id, name FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $userList = "📜 <b>Registered Users:</b>\n\n";
    foreach ($users as $user) {
        $userID = $user['telegram_user_id'];
        $userName = htmlspecialchars($user['name'], ENT_QUOTES); // Sanitize user name
        $userList .= "👤 <b><a href='tg://user?id={$userID}'>{$userName}</a></b> ❗️ ID: <b>{$userID}</b>\n";
    }

    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $userList,
        'parse_mode' => 'HTML'
    ]);
}
function viewPremiumUsersCommand($chat_id)
{
    global $pdo;

    $stmt = $pdo->query("SELECT telegram_user_id, name FROM users WHERE is_premium = 1");
    $premiumUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $premiumList = "🌟 <b>Premium Users:</b>\n\n";
    foreach ($premiumUsers as $user) {
        $userID = $user['telegram_user_id'];
        $userName = htmlspecialchars($user['name'], ENT_QUOTES); // Sanitize user name
        $premiumList .= "👤 <b><a href='tg://user?id={$userID}'>{$userName}</a></b> ❗️ ID: <b>{$userID}</b>\n";
    }

    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $premiumList,
        'parse_mode' => 'HTML'
    ]);
}
function addHostChannel($chat_id, $channel_id, $user_id)
{
    // Check if the channel is already taken by another user
    $status = isChannelTaken($channel_id);
    if ($status['taken']) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "<b>❌ This channel is already registered by user ID: <a href='tg://user?id=" . $status['user_id'] . "'>" . $status['user_id'] . "</a></b>",
            'parse_mode' => 'HTML'
        ]);
        return; // Exit the function if the channel is already taken
    }
    // Check if the user is free or premium
    $user_data = getUserData($user_id); // Function to fetch user data from the database
    $user_premium_status = $user_data['is_premium'] ?? 0; // Defaults to 0 if not found
    $method = 'host';
    if ($user_premium_status <= 0) {
        // Check if the user already has a host channel
        if (count(hasHostChannel($user_id)) >= 1) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "🚫 As a free user, you can only have one host channel.",
                'parse_mode' => 'Markdown'
            ]);
            return;
        } else {
            HandleConfig($chat_id, $user_id, $channel_id, $method);
        }
    } else {
        if (count(hasHostChannel($user_id)) >= 2) {
            // For premium users, add host channel logic here
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "🚫 As a premium user, you can only have two host channels." . count(hasHostChannel($user_id)),
                'parse_mode' => 'Markdown'
            ]);
            return;
        } else {
            HandleConfig($chat_id, $user_id, $channel_id, $method);
        }
    }
}
function addForwardingChannel($chat_id, $channel_id, $user_id)
{
    // Check if the channel is already taken by another user
    $status = isChannelTaken($channel_id);
    if ($status['taken']) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "<b>❌ This channel is already registered by user ID: <a href='tg://user?id=" . $status['user_id'] . "'>" . $status['user_id'] . "</a></b>",
            'parse_mode' => 'HTML'
        ]);
        return; // Exit the function if the channel is already taken
    }
    // Check if the user is free or premium
    $user_data = getUserData($user_id); // Function to fetch user data from the database
    $user_premium_status = $user_data['is_premium'] ?? 0; // Defaults to 0 if not found
    $method = 'forward';
    if ($user_premium_status <= 0) {
        // Check if the user already has 6 forwarding channels
        if (count(getForwardingChannels($user_id)) >= 10) {
            // Add the channel as a forwarding channel
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "🚫 As a free user, you can only add up to 10 forwarding channels.",
                'parse_mode' => 'Markdown'
            ]);
            return;
        } else {
            HandleConfig($chat_id, $user_id, $channel_id, $method);
        }
    } else {
        if (count(getForwardingChannels($user_id)) >= 20) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "🚫 As a premium user, you can only have 10 Forwarding channels.",
                'parse_mode' => 'Markdown'
            ]);
            return;
        } else {
            HandleConfig($chat_id, $user_id, $channel_id, $method);
        }
    }
}
function hasHostChannel($user_id)
{
    global $pdo;

    // Prepare the SQL statement to fetch the forwarding channels
    $stmt = $pdo->prepare("SELECT host_channels FROM user_configs WHERE telegram_user_id = ?");
    $stmt->execute([$user_id]);

    // Fetch the result
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // If there is a result, decode the JSON and return it
    if ($result) {
        return json_decode($result['host_channels'], true) ?: []; // Return an empty array if NULL
    }

    // If no user found, return an empty array
    return 0;
}
function getForwardingChannels($user_id)
{
    global $pdo;

    // Prepare the SQL statement to fetch the forwarding channels
    $stmt = $pdo->prepare("SELECT forwarding_channels FROM user_configs WHERE telegram_user_id = ?");
    $stmt->execute([$user_id]);

    // Fetch the result
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // If there is a result, decode the JSON and return it
    if ($result) {
        return json_decode($result['forwarding_channels'], true) ?: []; // Return an empty array if NULL
    }

    // If no user found, return an empty array
    return 0;
}
function GetUserConfig($user_id)
{
    global $pdo;

    // Check if the user exists in the database
    $stmt = $pdo->prepare("SELECT * FROM user_configs WHERE telegram_user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function CreateConfig($user_id)
{
    global $pdo;

    // Insert a new user with default values
    $stmt = $pdo->prepare("INSERT INTO user_configs (telegram_user_id, host_channels, forwarding_channels) VALUES (?, '[]', '[]')");
    $stmt->execute([$user_id]);
    return true;
}
function HandleConfig($chat_id, $user_id, $channel_id, $method)
{
    global $pdo;

    // Check if the user exists
    $is_config = GetUserConfig($user_id);

    // If user does not exist, create a new user record
    if (!$is_config) {
        CreateConfig($user_id);
    }

    $result = GetUserConfig($user_id);

    if ($method === 'host') {
        // Decode the current host channels or initialize an empty array
        $host_channels = $result['host_channels'] ? json_decode($result['host_channels'], true) : [];

        // Check if the channel ID is already in the list
        if (!in_array($channel_id, $host_channels)) {
            // Add the new channel ID to the list
            $host_channels[] = $channel_id;

            // Update the database with the new host channels, storing as JSON
            $stmt = $pdo->prepare("UPDATE user_configs SET host_channels = ? WHERE telegram_user_id = ?");
            $stmt->execute([json_encode($host_channels), $user_id]);

            // Send success message
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ Host channel added successfully! 🎉",
                'parse_mode' => 'Markdown'
            ]);

            return true; // Successfully updated
        } else {
            // Channel ID already exists in the host channels
            sendTelegramRequest('sendMessage', params: [
                'chat_id' => $chat_id,
                'text' => "❌ Failed to Add Host channel 💀 This channel is already registered.",
                'parse_mode' => 'Markdown'
            ]);
            return false; // Indicate that no update was made
        }
    } elseif ($method === 'forward') {
        // Decode the current forwarding channels or initialize an empty array
        $forwarding_channels = $result['forwarding_channels'] ? json_decode($result['forwarding_channels'], true) : [];

        // Check if the channel ID is already in the list
        if (!in_array($channel_id, $forwarding_channels)) {
            // Add the new channel ID to the list
            $forwarding_channels[] = $channel_id;

            // Update the database with the new forwarding channels, storing as JSON
            $stmt = $pdo->prepare("UPDATE user_configs SET forwarding_channels = ? WHERE telegram_user_id = ?");
            $stmt->execute([json_encode($forwarding_channels), $user_id]);

            // Send success message
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "✅ Forwarding channel added successfully! 🎉",
                'parse_mode' => 'Markdown'
            ]);

            return true; // Successfully updated
        } else {
            // Channel ID already exists in the forwarding channels
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ Failed to Add Forwarding channel 💀 This channel is already registered.",
                'parse_mode' => 'Markdown'
            ]);
            return false; // Indicate that no update was made
        }
    }

    return false; // In case an invalid method is passed
}
