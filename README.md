# MySQL (Percona) in Docker with xtrabackup restore, reachable from Power BI Desktop

**English** | [فارسی](README.fa.md)

Each project is one folder containing a Docker Compose stack:

- **`db`**: Percona Server 8.0.35 (the database Power BI connects to).
- **`scheduler`**: a helper container that rebuilds the database from an xtrabackup chain (one full backup plus incrementals) using `xb.php`.

Every name (containers, volumes, backup directory, port) is derived from the `.env` file, so you can run many independent projects on one server.

---

## 1. Requirements

- Linux server with Docker and the Docker Compose plugin (`docker compose version` must work)
- `git`, `sudo`, `openssl`
- A user that can run `docker` (member of the `docker` group, or use `sudo`)
- On your Windows PC: Power BI Desktop and MySQL Connector/NET 8.x

---

## 2. Clone the project with a custom name

The folder name becomes the project name. Use lowercase letters, digits, `-` and `_` only.

```bash
cd ~
git clone https://github.com/alirezaMalekei/pbiSource.git myproject      # creates ~/myproject
cd myproject
```

The folder name and `PROJECT` in `.env` (next step) must be identical. The project can live in any directory (home, `/srv/apps`, `/opt`, ...).

Optional: detach the clone from the template repository so you can't push to it by accident:

```bash
git remote remove origin
```

---

## 3. Configure `.env`

```bash
cp .env.example .env        # or create it
nano .env
chmod 600 .env
```

```env
PROJECT=myproject                  # must equal the folder name
DB_PORT=3307                       # host port, must be unique on the server
DB_BIND=0.0.0.0                    # 0.0.0.0 = reachable from outside, 127.0.0.1 = server only
MYSQL_ROOT_PASSWORD=CHANGE_ME      # openssl rand -hex 16
TZ=UTC
BACKUP_ROOT=/srv/backups           # absolute path; backups go to $BACKUP_ROOT/$PROJECT
```

Notes:

- `BACKUP_ROOT` must be an absolute path. `~` is not expanded inside `.env`.
- Never commit `.env` to Git.
- Optional: pin the xtrabackup image by adding `XB_IMAGE=percona/percona-xtrabackup:<exact-tag>` to `.env` and `XB_IMAGE: ${XB_IMAGE:-percona/percona-xtrabackup:8.0}` under `scheduler.environment` in `docker-compose.yml`. Its major/minor version must match the database (8.0.35).

---

## 4. Install the `pbi` helper commands (once per server)

The repository ships two management scripts in `tools/`:

| Script | Command | Purpose |
|---|---|---|
| `tools/dcup.sh` | `pbi` | Start a project after safety checks |
| `tools/dcrm.sh` | `pbi-rm` | Delete a project completely |

They are shared tools, not part of any single project. Install them once; they then work for every project on the server:

```bash
sudo install -m 755 tools/dcup.sh /usr/local/bin/pbi
sudo install -m 755 tools/dcrm.sh /usr/local/bin/pbi-rm
which pbi pbi-rm
```

`install` makes a **copy**, so the commands keep working after you delete the project folder. To update them later, run `git pull` and the two `install` commands again.

> Don't symlink to the copies inside a project folder (`ln -s ./tools/dcup.sh /usr/local/bin/pbi`). The link breaks as soon as that project is deleted.

---

## 5. Start the project

```bash
cd ~/myproject
pbi
```

Before building anything, `pbi` checks that:

- `.env` exists and `PROJECT`, `DB_PORT`, `MYSQL_ROOT_PASSWORD` are valid
- `BACKUP_ROOT` is an absolute path
- the port is not already used by another program on the server
- no other project in the same parent folder uses the same port
- the container names don't belong to a project in another folder

If everything is fine it creates the backup directory and runs `docker compose up -d --build`. On any problem it prints an error and builds nothing.

Any other argument is passed to `docker compose`:

```bash
pbi ps
pbi logs --tail 20 db
pbi down            # stops and removes containers, keeps volumes and data
```

You can also pass the project folder explicitly: `pbi ~/myproject ps`.

Verify:

```bash
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
ss -tlnp | grep 3307
```

Expected: `myproject-db` is `healthy` and the port shows `0.0.0.0:3307->3306/tcp` (or `127.0.0.1:3307` if `DB_BIND=127.0.0.1`).

---

## 6. Restoring from backups with `xb.php`

Place backups in `$BACKUP_ROOT/$PROJECT/<backup-name>/`. Each backup folder is one xtrabackup backup containing `xtrabackup_checkpoints`:

```
/srv/backups/myproject/
├── full_1/       (full backup)
├── inc_1/        (incremental on top of full_1)
└── inc_2/        (incremental on top of inc_1)
```

Run from the project folder:

```bash
docker compose exec -T scheduler php /app/xb.php status          # show chain and last restored LSN
docker compose exec -T scheduler php /app/xb.php cycle           # restore if there is a newer backup
docker compose exec -T scheduler php /app/xb.php cycle --force   # restore even if nothing is new
```

What `cycle` does: finds the longest valid chain, prepares a copy in a work volume (the database stays online during this), then stops the database, **deletes the whole data directory**, copies the prepared backup in, fixes ownership and starts the database again.

Important consequences:

- **All data, the root password and all MySQL users are replaced by what is in the backup.** After a restore, the root password is the one from the source server, not `MYSQL_ROOT_PASSWORD` from `.env`. This is why root login can fail with `Access denied` on a restored database.
- **`pbi_user` (section 7) disappears after a restore unless it exists in the source database.** Create it on the source server, or re-create it after each restore.
- To run it automatically, schedule it on the host, for example in `crontab -e`:
  ```cron
  0 11 * * * cd ~/pbisources/myproject && docker compose exec -T scheduler php /app/xb.php cycle >> ~/pbisources/myproject-cycle.log 2>&1
  ```

---

## 7. Create a read-only MySQL user for Power BI

Open the MySQL shell inside the container (run this in the **server terminal**):

```bash
docker exec -it myproject-db mysql -uroot -p
```

Type the root password when asked. If the database was restored from a backup, use the source server's root password. If the database was freshly created, it is `MYSQL_ROOT_PASSWORD` from `.env`.

Only SQL works at the `mysql>` prompt. Linux commands such as `sudo` or `iptables` produce `ERROR 1064`. Every statement must end with `;`, and you should type with an English keyboard layout. To cancel a half-typed statement enter `\c`.

```sql
SHOW DATABASES;
```

Ignore `information_schema`, `mysql`, `performance_schema` and `sys`. Pick your own database (example: `testdb`), then:

```sql
CREATE USER 'pbi_user'@'YOUR_PUBLIC_IP' IDENTIFIED WITH mysql_native_password BY 'A_LONG_RANDOM_PASSWORD';
GRANT SELECT ON testdb.* TO 'pbi_user'@'YOUR_PUBLIC_IP';
FLUSH PRIVILEGES;
SHOW GRANTS FOR 'pbi_user'@'YOUR_PUBLIC_IP';
```

- `YOUR_PUBLIC_IP` is the public IP of the computer running Power BI (see `whatismyip.com` on that computer). If your IP changes often, use `'%'` instead and rely on the firewall in section 8.
- `GRANT SELECT` makes the user read-only. Never give Power BI the root account.
- `mysql_native_password` avoids authentication problems with older MySQL connectors. Percona 8.0.35 still supports it.
- To change the password later: `ALTER USER 'pbi_user'@'YOUR_PUBLIC_IP' IDENTIFIED BY 'new password';`
- To delete the user: `DROP USER 'pbi_user'@'YOUR_PUBLIC_IP';`

Leave the shell with `exit;`.

---

## 8. Open the port only for your IP

Docker bypasses `ufw`, so restrict access in the `DOCKER-USER` chain. Replace `3307` with your `DB_PORT` and the IP with your public IP:

```bash
sudo iptables -I DOCKER-USER -p tcp --dport 3307 ! -s YOUR_PUBLIC_IP -j DROP
```

Meaning: drop every TCP packet to this port that does not come from your IP.

Check or remove the rule:

```bash
sudo iptables -L DOCKER-USER -n -v --line-numbers
sudo iptables -D DOCKER-USER -p tcp --dport 3307 ! -s YOUR_PUBLIC_IP -j DROP
```

The rule disappears after a reboot. To make it permanent:

```bash
sudo apt install iptables-persistent
sudo netfilter-persistent save
```

On a cloud server also allow TCP `DB_PORT` from your IP in the provider's firewall or security group. If your IP changes, you lose access until you update the MySQL user and this rule.

`DB_BIND=127.0.0.1` makes the port reachable from the server only, so Power BI on another computer cannot connect. For remote access keep `DB_BIND=0.0.0.0` together with the firewall rule above, or use a VPN.

---

## 9. Connect from Power BI Desktop

### 9.1 Check the network (Windows PowerShell)

```powershell
Test-NetConnection SERVER_IP -Port 3307
```

`TcpTestSucceeded : True` means the network path is open. If it says `False`, do not touch Power BI yet; check the port binding, the firewall rule, the cloud firewall and your own IP (section 11).

### 9.2 Install the driver

Install **MySQL Connector/NET 8.x** from the official MySQL downloads page, then close Power BI completely and reopen it.

### 9.3 Connect

1. **Home → Get data → More...**
2. Search for **MySQL**, choose **MySQL database**, click **Connect**.
3. Fill in:

   | Field | Value |
   |---|---|
   | Server | `SERVER_IP:3307` (the server's IP and your `DB_PORT`) |
   | Database | `testdb` (your database name) |

4. Click **OK**. In the credentials window choose the **Database** tab (not Windows):
   - User name: `pbi_user`
   - Password: the password of `pbi_user` (not the root password)
5. Click **Connect**. In the Navigator tick the tables you need and click **Load** (or **Transform Data** to edit in Power Query first).

Server is the **server's** IP. Your own IP is used only in the MySQL user and the firewall rule.

If wrong credentials were saved: **File → Options and settings → Data source settings → Global permissions →** select the server **→ Clear Permissions**, then connect again.

---

## 10. Remove a project completely

```bash
cd ~/myproject
pbi-rm
```

It shows what will be deleted (containers, volumes, the project folder, `$BACKUP_ROOT/$PROJECT`) and asks you to type the project name. This cannot be undone. Remove the firewall rule for the port yourself afterwards.

To stop a project but keep its data, use `pbi down`. Never run `docker compose down -v` unless you intend to delete the database.

---

## 11. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Test-NetConnection` is `False` | Port bound to `127.0.0.1` (set `DB_BIND=0.0.0.0` and run `pbi up -d`), blocked by the firewall rule (is the IP in `! -s` your current public IP?), the cloud firewall, or the VM is behind NAT |
| Power BI: *We couldn't authenticate with the credentials provided* | The network works. Wrong password, the user doesn't exist, the host in `'pbi_user'@'...'` differs from the IP the server sees, or the auth plugin. Check with `SELECT user, host, plugin FROM mysql.user;` |
| `Access denied for user 'root'@'localhost'` | The root password in the database differs from `.env` (typical after a restore). Use the source server's root password |
| `ERROR 1064` in `mysql>` | A Linux command was typed in the SQL shell, a `;` is missing, or a stray character was pasted. Cancel with `\c`, type with an English keyboard |
| `pbi` says the port is in use | Pick another `DB_PORT` |
| Driver not found in Power BI | Install MySQL Connector/NET 8.x and restart Power BI |
| SSL error | Change the Encrypt option in Power BI, or check `SHOW VARIABLES LIKE 'have_ssl';` |
| `No valid backup chain found` | Backups are not in `$BACKUP_ROOT/$PROJECT/<name>/xtrabackup_checkpoints` |
| `Volume ... does not exist` | `PROJECT` in `.env` was changed after the stack was created |
| Port change has no effect | Ports are applied only when the container is recreated: `pbi up -d` |

The repeating `mysql_native_password is deprecated` warning in `docker logs` comes from the healthcheck and is harmless.

---

## 12. Test the whole backup/restore flow

`test.sh` creates a temporary source database, takes a full and two incremental backups, deletes the source, restores with `xb.php` and checks the data (`a,b,c,d`). Run it only in a **test project**; it wipes that project's database, volumes and backups.

```bash
cd ~/test
chmod +x test.sh
./test.sh             # asks you to type the project name; use -y to skip
```

Afterwards the test database's root password is `srcpass`. Remove the project with `pbi-rm` or change that password.

---

## 13. Security checklist

- Use a dedicated read-only user (`GRANT SELECT`), never root, for Power BI.
- Use long random passwords and don't paste them in chats.
- Allow the port only from your IP; use a VPN or `DB_BIND=127.0.0.1` when remote access isn't needed.
- Keep `.env` out of Git (`.gitignore`) and `chmod 600 .env`.
- The `scheduler` container mounts `/var/run/docker.sock`, which gives it root-level control of the host. Never publish a port from it.
- Keep the backups outside Docker volumes (they are plain host folders) and copy them off the server as well.

---

## Repository layout

```
myproject/
├── docker-compose.yml   # db + scheduler, everything driven by .env
├── Dockerfile           # scheduler image (PHP CLI + docker CLI)
├── xb.php               # backup chain discovery and restore
├── test.sh              # end-to-end restore test
├── .env.example         # template for your settings
├── .gitignore           # must contain .env
├── README.md            # English documentation
├── README.fa.md         # Persian documentation
└── tools/
    ├── dcup.sh          # installed as `pbi`
    └── dcrm.sh          # installed as `pbi-rm`
```

Your real `.env` lives in each project folder and is never committed.
