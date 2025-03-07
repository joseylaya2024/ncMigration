#!/bin/bash
bash <<EOF
curl -X DELETE -H "Authorization: Bearer ..." "https://storage.googleapis.com/nc_webrtc_recording/78974d5f-55a8-4469-85a8-e81002001b05/dsa321/audio.ogg" >> /Applications/XAMPP/xamppfiles/htdocs/nc_migration/logs/process.log 2>&1 &
EOF
