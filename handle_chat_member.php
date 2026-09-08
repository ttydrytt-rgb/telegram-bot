<?php
function handleChatMember($chatMemberUpdate) {
    global $BOT_TOKEN;
    
    // Check if the user was added or joined
    $newChatMember = $chatMemberUpdate['new_chat_member'] ?? null;
    $status = $newChatMember['status'] ?? '';
    $userId = $newChatMember['user']['id'] ?? null;
    
    if (($status === 'member' || $status === 'creator' || $status === 'administrator') && $userId) {
        $autoPayments = readJson('auto_payment.json');
        
        // Find if this user just paid via auto payment
        if (isset($autoPayments[$userId]) && $autoPayments[$userId]['status'] === 'paid' && !empty($autoPayments[$userId]['claimed'])) {
            sendMessage($userId, "Welcome 💖");
        }
    }
}
