#!/bin/bash
echo "Packaging Sender plugin..."

# Step up one directory to capture the folder structure for WordPress
cd ..

# Zip the folder, outputting it inside the repo, excluding git, logs, zips, and this script
zip -r fsbhoa_website_user_sender/fsbhoa_website_user_sender.zip fsbhoa_website_user_sender -x "*.git*" "*.DS_Store" "*debug.log" "*/build_release.sh" "*/*.zip"

echo ""
echo "Copy zip file to PC using:"
echo "   scp pi@testbed.fsbhoa.com:~/fsbhoa_website_user_sender/fsbhoa_website_user_sender.zip C:\\Users\\dkeen\\Downloads\\"
echo "Done! Created: fsbhoa_website_user_sender.zip"

