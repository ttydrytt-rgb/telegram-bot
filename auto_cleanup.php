<?php
// Cleanup script for auto_payment.json
$baseDir = __DIR__;
$autoPaymentFile = $baseDir . '/auto_payment.json';

if (file_exists($autoPaymentFile)) {
    $fp = @fopen($autoPaymentFile, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $autoPayments = json_decode($content, true) ?: [];
        $changed = false;
        
        $currentTime = time();
        foreach ($autoPayments as $userId => $session) {
            if ($session['status'] === 'pending' && ($currentTime - $session['created_at']) > 1800) {
                // Older than 30 minutes and still pending
                unset($autoPayments[$userId]);
                $changed = true;
            }
        }
        
        if ($changed) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($autoPayments, JSON_PRETTY_PRINT));
            fflush($fp);
        }
        
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
