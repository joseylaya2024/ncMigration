<?php
    require 'vendor/autoload.php';
    require 'config/db.php';
    
    use Google\Cloud\Storage\StorageClient;

    $token = getToken('');
    $bucketName = 'nc_webrtc_recording';
    $skywayPath = '78974d5f-55a8-4469-85a8-e81002001b05';
    
    $monthDate = $_GET['date'] ?? "2022-09-01";
    $offset = $_GET['offset'] ?? 0;
    $batchSize = 900;

    $message = 'Active execution';

    if ($monthDate > date('Y-m-d')) {
        $message = 'date is greater than the current date. Stopping execution.';
        die($message);
    }
   

    $startDate = date('Y-m-d 00:00:00', strtotime($monthDate));
    $endDate = date('Y-m-d 23:59:59', strtotime("$monthDate +1 month -1 day"));
    $deleted_flg = 1;
    
    try {
        if (!$db || $db->connect_errno) {
            die("Database connection failed!");
        }
    
        $stmt = $db->prepare("SELECT id, recording_id, chat_hash, user_id 
                FROM lesson_audio_files_backup 
                WHERE created BETWEEN ? AND ? 
                AND deleted_flg = ? 
                ORDER BY id ASC 
                LIMIT ? OFFSET ?");

        $stmt->bind_param("sssii", $startDate, $endDate, $deleted_flg, $batchSize, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
    
        $recordings = [];
        while ($row = $result->fetch_assoc()) {
            $recordings[] = $row;
        }
        $stmt->close();
    
        if (!empty($recordings)) {
            $prevOffset = $offset;
            $deleteFile = processData($recordings, $token, $bucketName, $skywayPath);
            $offset += $batchSize;

           if (!empty($deleteFile['success']) && $deleteFile['success']) {
                echo "<script>
                    setTimeout(function() {
                        window.location.href = 'index.php?date=$monthDate&offset=$offset';
                    }, 3000);
                </script>";
            }else{
                if (!empty($deleteFile['error'])) {
                    logMessage("empty file: date=$monthDate limit=$batchSize offset=$prevOffset" . (isset($deleteFile['error']) ? $deleteFile['error'] : "Unknown error"), "failed");
                }
                 echo "<script>
                        setTimeout(function() {
                            window.location.href = 'index.php?date=$monthDate&offset=0';
                        }, 3000);
                    </script>";
            }
        } else {
            $prevMonthDate = $monthDate;
            $monthDate = date('Y-m-d', strtotime("$monthDate +1 month"));
            logMessage("empty file: date=$prevMonthDate limit=$batchSize offset=$offset" );
             echo "<script>
                    setTimeout(function() {
                        window.location.href = 'index.php?date=$monthDate&offset=0';
                    }, 3000);
                </script>";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
    
    $db->close();
    
    function getToken() {
        try {
            $tokenFile = __DIR__ . '/gcloudAccessToken/gcloudToken.php';
    
            if (file_exists($tokenFile)) {
                ob_start();
                include($tokenFile);
                $token = trim(ob_get_clean());
    
                return str_replace("Access Token: ", "", $token);
            } else {
                throw new Exception("Token file not found!");
            }
        } catch (Exception $e) {
            die("Error: " . $e->getMessage());
        }
    }
    
    function processData($recordings, $token, $bucketName, $skywayPath) {
        if (!empty($recordings)) {

            // Path to your shell script
            $filename = 'bin/delete_skyway_command.sh';
            // $filename = "/tmp/delete_skyway_command.sh";
            file_put_contents($filename, "#!/bin/bash\nbash <<EOF\n");

            foreach ($recordings as $recordItem) {
                
                // Get recording id
                $file = !empty($recordItem['recording_id']) ? $recordItem['recording_id'] : '';

                // Skip if empty
                if (!$file) {
                    continue;
                }

                // Construct object name
                $objectName = $skywayPath . '/' . $file . '/audio.ogg';

                // Construct command
                $commandString = "curl -X DELETE -H \"Authorization: Bearer $token\" \"https://storage.googleapis.com/{$bucketName}/{$objectName}\" >> /Applications/XAMPP/xamppfiles/htdocs/nc_migration/logs/process.log 2>&1 &\r\n";

                logMessage("deleting file: " . $recordItem['recording_id'] . " recording_id", "records");

                file_put_contents($filename, "$commandString", FILE_APPEND);
            }
             // Close the pipeline
             file_put_contents($filename, "EOF\n", FILE_APPEND);

             // Execute the shell script
             exec("./$filename", $output, $return_var);

             return [
                'success' => true
            ];
         } else {
            return [
                'success' => false,
                'error' => "empty recording file",
            ];
        }
    }
    
    function logMessage($message, $path = "debug") {
        $logDir = __DIR__ . "/logs/";
    
        // Ensure the logs directory exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
    
        $logFile = $logDir . $path . ".log";
        $maxLines = 5000; 
    
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (count($lines) >= $maxLines) {
                file_put_contents($logFile, ""); 
            }
        }

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Cloud Storage Files</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-shadow: 2px 2px 10px rgba(0, 0, 0, 0.1);
        }
        h2 {
            text-align: center;
        }
        ul {
            list-style-type: none;
            padding: 0;
        }
        li {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .date {
            font-weight: bold;
            color: green;
            word-break: break-all;
        }
        .status {
            font-weight: bold;
            color: blue;
            word-break: break-all;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Local Migration</h2>
    
    <p><strong>Date Start:</strong> <span class="date"><?php echo $startDate; ?></span> | <strong>Date End:</strong> <span class="date"><?php echo $endDate; ?></span></p>
    <?php 
        if(!empty($message)) : ?>
            <p><strong>Status:</strong> <span class="status"><?php echo $message; ?></span></p>
        <?php endif;
    ?>
</div>

</body>
</html>
