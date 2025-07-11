<?php
namespace App\Exceptions;
class Console{

    public static function logger($message) : void {
       file_put_contents(__DIR__ . '/log.txt', $message. PHP_EOL, FILE_APPEND);
    }

    public static function log($message) : void {
        $new_message = is_string($message) ? $message : json_encode($message);
        self::logger( date("Y-m-d") . ' => 📝[LOG]📝 '. $new_message);
    }

    public static function log2( $message, $logData) : void {
        $new_message = is_string($logData) ? $logData : json_encode($logData);
        self::logger( date("Y-m-d") . ' => 📝[LOG]📝 '.$message. $new_message);
    }

    public static function info($message) : void {
        self::logger( date("Y-m-d") . ' =>  🔗[INFO]🔗 '. $message);
    }

    public static function error($message): void 
    {
        $logMessage = date("Y-m-d H:i:s") . ' ❌[ERROR]❌ ' . $message . PHP_EOL;
        self::logger($logMessage);
        
        // Also log to syslog for system monitoring
        // syslog(LOG_ERR, $message);
        
        // Consider adding error notification (Slack, Email, etc.)
        self::notifyAdmin($message);
    }
    
    private static function notifyAdmin(string $message): void
    {
        // Implement your notification logic here
        // Could be Slack webhook, Email, etc.
    }

    public static function warn($message) : void {
        self::logger(date("Y-m-d") . ' => ⚠️[WARNING]⚠️ '. $message);
    }

    public static function debug($message) : void {
        self::logger(date("Y-m-d") . ' => 🐞🛠️[DEBUG]🪲🚧 '. $message);
    }

    public static function dd(...$vars) { // dump and die
        foreach ($vars as $var) {
            var_dump($var);
        }
        die(1);
    }
}
