<?php
    require 'vendor/autoload.php';
    require 'config/db.php';

    use Google\Cloud\Storage\StorageClient;

    class GCSMigration {
        private $db;
        private $bucketName = 'nc_webrtc_recording';
        private $skywayPath = '78974d5f-55a8-4469-85a8-e81002001b05';
        private $batchSize = 900;
        private $token;
        public $message;
        public $startDate;
        public $endDate;
        
        public function __construct($db) {
            $this->db = $db;
            $this->token = $this->getToken();
            $this->message = 'Active execution';
            $this->startDate = '';
            $this->endDate = '';
        }
        
        public function run($monthDate, $offset) {
            if ($monthDate > date('Y-m-d')) {
                $this->message = 'Date is greater than the current date. Stopping execution.';
                return;
            }
            
            $this->startDate = date('Y-m-d 00:00:00', strtotime($monthDate));
            $this->endDate = date('Y-m-d 23:59:59', strtotime("$monthDate +1 month -1 day"));
            $deleted_flg = 1;
            
            try {
                if (!$this->db || $this->db->connect_errno) {
                    $this->message = "Database connection failed!";
                    return;
                }
                
                $stmt = $this->db->prepare("SELECT id, recording_id, chat_hash, user_id FROM lesson_audio_files WHERE created BETWEEN ? AND ? AND deleted_flg = ? ORDER BY id ASC LIMIT ? OFFSET ?");
                $stmt->bind_param("sssii", $this->startDate, $this->endDate, $deleted_flg, $this->batchSize, $offset);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $recordings = [];
                while ($row = $result->fetch_assoc()) {
                    $recordings[] = $row;
                }
                $stmt->close();
                
                if (!empty($recordings)) {
                    $deleteFile = $this->processData($recordings);
                    $offset += $this->batchSize;

                    $this->redirect($monthDate, $offset);
                } else {
                    $monthDate = date('Y-m-d', strtotime("$monthDate +1 month"));
                    $offset = 0;
                    $this->redirect($monthDate, $offset);
                }
            } catch (Exception $e) {
                $this->message = "Error: " . $e->getMessage();
            }
        }
        
        private function getToken() {
            $tokenFile = __DIR__ . '/gcloudAccessToken/gcloudToken.php';
            if (file_exists($tokenFile)) {
                ob_start();
                include($tokenFile);
                return trim(str_replace("Access Token: ", "", ob_get_clean()));
            }
            throw new Exception("Token file not found!");
        }
        
        private function processData($recordings) {
            $filename = 'bin/delete_skyway_command.sh';
            file_put_contents($filename, "#!/bin/bash\nbash <<EOF\n");
            
            foreach ($recordings as $recordItem) {
                $file = $recordItem['recording_id'] ?? '';
                if (!$file) continue;
                
                $objectName = $this->skywayPath . '/' . $file . '/audio.ogg';
                $commandString = "curl -X DELETE -H \"Authorization: Bearer {$this->token}\" \"https://storage.googleapis.com/{$this->bucketName}/{$objectName}\" >> logs/process.log 2>&1 &\r\n";
                
                $this->logMessage("Deleting file: " . $file, "records");
                file_put_contents($filename, $commandString, FILE_APPEND);
            }
            
            file_put_contents($filename, "EOF\n", FILE_APPEND);
            exec("./$filename");
            
            return ['success' => true];
        }
        
        private function logMessage($message, $path = "debug") {
            $logDir = __DIR__ . "/logs/";
            if (!is_dir($logDir)) mkdir($logDir, 0777, true);
            
            $logFile = $logDir . $path . ".log";
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
        }
        
        private function redirect($monthDate, $offset) {
            echo "<script>
                    setTimeout(function() {
                        window.location.href = 'index.php?date=$monthDate&offset=$offset';
                    }, 3000);
                </script>";
        }
    }

    $monthDate = $_GET['date'] ?? "2022-09-01";
    $offset = $_GET['offset'] ?? 0;
    $migration = new GCSMigration($db);
    $migration->run($monthDate, $offset);
?>

<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Google Cloud Storage Files</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ccc; border-radius: 5px; box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1); }
            h2 { text-align: center; }
            .date, .status { font-weight: bold; word-break: break-word; }
            .date { color: green; }
            .status { color: blue; }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>Local Migration</h2>
            <p><strong>Date Start:</strong> <span class="date"><?php echo htmlspecialchars($migration->startDate, ENT_QUOTES, 'UTF-8'); ?></span> | <strong>Date End:</strong> <span class="date"><?php echo htmlspecialchars($migration->endDate, ENT_QUOTES, 'UTF-8'); ?></span></p>
            <p><strong>Status:</strong> <span class="status"><?php echo htmlspecialchars($migration->message, ENT_QUOTES, 'UTF-8'); ?></span></p>
        </div>
    </body>
</html>
