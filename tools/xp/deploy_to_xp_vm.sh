#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
vmx='/home/waterrun/VM/Windows/Windows_XP_SP3/Windows XP Professional/Windows XP Professional.vmx'
guest_root='C:\stoneChat'

cd "$root"
find Pages Server Assets ModernNetwork -type f -print > /tmp/stonechat-xp-deploy-files
printf '%s\n' README.org RUN.cmd CONF_SMP.INI >> /tmp/stonechat-xp-deploy-files

while IFS= read -r file; do
    dir=$(dirname "$file")
    guest_dir="$guest_root\\${dir//\//\\}"
    guest_file="$guest_root\\${file//\//\\}"
    vmrun -T ws -gu Administrator -gp 2288 runProgramInGuest "$vmx" cmd.exe /c "if not exist $guest_dir mkdir $guest_dir" >/dev/null 2>&1 || true
    vmrun -T ws -gu Administrator -gp 2288 copyFileFromHostToGuest "$vmx" "$root/$file" "$guest_file"
done < /tmp/stonechat-xp-deploy-files

vmrun -T ws -gu Administrator -gp 2288 copyFileFromGuestToHost "$vmx" 'C:\stoneChat\Pages\router.php' /tmp/stonechat-xp-router.php
cmp -s Pages/router.php /tmp/stonechat-xp-router.php
echo "DEPLOYED $(wc -l < /tmp/stonechat-xp-deploy-files) files"
