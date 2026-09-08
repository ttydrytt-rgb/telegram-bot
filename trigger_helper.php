<?php
// Karn/trigger_helper.php
// Helper to launch background processes without exec() using fsockopen

function triggerBackgroundProcess($scriptName) {
    // Determine the absolute path to the script
    // Assumes script is in the same directory as this helper or relative to it
    $scriptPath = __DIR__ . '/' . $scriptName;

    // Fix for webapp subdirectory if needed
    if (!file_exists($scriptPath)) {
        // Try one level up if we are in a subdirectory (like webapp)
        $scriptPath = dirname(__DIR__) . '/' . $scriptName;
    }

    if (file_exists($scriptPath)) {
        // Execute PHP script in background
        // Works on Linux/Unix systems (Replit, VPS, etc.)
        $cmd = "php " . escapeshellarg($scriptPath) . " > /dev/null 2>&1 &";
        exec($cmd);
        return true;
    } else {
        error_log("Trigger Failed: Script not found at $scriptPath");
        return false;
    }
}
?>
