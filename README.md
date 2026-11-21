# Device Backups

Device Backups reads the device ID, hostname, and detected OS from LibreNMS. Backups run only for enrolled, enabled devices. The enrollment command stores the SSH settings as an encrypted device attribute.

## Setup

In **Device Backups → Settings**, configure the separate backup database and save a **Backup Report Email** address. Reports use the LibreNMS email backend and sender. Each report lists successful and failed devices, and successful entries include a backup ID.

Set `DEVICE_BACKUPS_ARCHIVE_PASSWORD` in LibreNMS's environment. Keep a copy needed for recovery with your other backup credentials. PHP CLI needs ZIP with AES-256 support, zlib, and Phar.

From `/opt/librenms` as the LibreNMS service user, create the backup tables on the configured backup connection:

```bash
./lnms migrate --database=device_backups \
  --path=vendor/daryl-peterson/librenms-device-backups/database/migrations --force
```

Run this migration again after changing to a different backup database.

## Enroll and run

In LibreNMS, open **Device Backups → SSH Status**, find a device, and choose **Configure SSH**. Enter the SSH account, port, profile, and verified host key fingerprint. **Read Host Key** shows the fingerprint presented by the device so you can compare it with a trusted source. Save the settings and use **Test SSH** to verify login. The SSH Status page shows the latest check from a manual test or backup run.

You can also enroll a device from the CLI:


```bash
./lnms device-backups:configure DEVICE_ID
./lnms device-backups:run DEVICE_ID --check
./lnms device-backups:run DEVICE_ID
```

Enter the SSH username and password, port, and independently verified SHA256 host key fingerprint at the prompts. For switches, confirm the CLI hostname and enter the enable password if required. Use `--profile=PROFILE` to override the detected OS or select a server profile:

| Platform | Profiles |
| --- | --- |
| Switches and firewalls | `dell`, `ios`, `nxos`, `asa`, `timos` |
| DHCP, DNS, and Kea | `dhcp`, `dns`, `dhcp-dns`, `dhcp-dns-old`, `dns-old`, `kea` |
| Hosts with restricted export helpers | `dhcp-helper`, `kea-helper` |

Standard server profiles require an SSH account that can read the selected files. Helper profiles call the existing `/usr/local/sbin/netmonitor-dhcp-backup` or `/usr/local/sbin/netmonitor-kea-backup` via noninteractive sudo. Telnet and the deployment-specific SMx export are outside these profiles.

To start a single-device backup from LibreNMS, click **Run Backup** on the device overview, its backup history, or the **Backups by Device** list. LibreNMS processes requests through its existing scheduler, including when recurring backups are paused. A queued or running request appears in the device history and on the Status page.

Omit the device ID to run all enrolled, enabled devices. Under **Device Backups → Schedule**, choose the days, time, and time zone, then enable scheduled backups and save. LibreNMS uses the saved schedule for all enrolled, enabled devices. Check the entry with `./lnms schedule:list`. An existing `DEVICE_BACKUPS_SCHEDULED=true` setting supplies the Friday 6 p.m. America/Chicago schedule until you save a schedule in the web interface.

Remove a device from future runs with `./lnms device-backups:configure DEVICE_ID --disable`. Its stored backups remain available.

## Restore files

The backup database stores the run, per-device results, and AES-256 ZIP data. Email contains the summary only. Export a successful backup ID from the report and open the ZIP with the archive password:

```bash
./lnms device-backups:export BACKUP_ID /path/to/new-backup.zip
```

The export command verifies the stored checksum before publishing the file. Test restoration separately for each device or server profile.
