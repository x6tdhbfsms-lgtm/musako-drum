# MUSAKO Oracle Cloud 試験運用手順

作成日：2026-10-10。基準：`39acddbe14e90845ee9d522802acc5a680acf223`。
対象はmusako-drumのみ。別のstudio34リポジトリ、既存DB、確認用8コマは変更しない。

**今回はリポジトリ準備まで。Oracle上のリソース作成・課金操作・公開・GitHubへのpushは実施していない。以下のクラウド操作は利用者が次の段階で行う。**

## 1. 現行構成と今回の分離

現在の開発環境はLaravel 13.31.0、PHP 8.5、MySQL 8.4、Laravel Sailの開発Webサーバー、Vite 8.3.0。Queueはdatabase、メールはMailpit、SchedulerはAsia/Tokyo。開発用compose.yamlと.envは維持する。

Oracle用は `compose.oracle.yaml` を単独使用し、`docker/oracle/compose.sh` で必ず別プロジェクト `musako-oracle` と `.env.oracle` を選択する。開発Composeと重ねない。

| サービス | 内容 | 永続化／公開 |
|---|---|---|
| web | Caddy、自動HTTPS、静的assets | 証明書data/config。80・443/TCPのみ |
| app | PHP 8.5 FPM、Composer --no-dev | storageを専用volume。9000は非公開 |
| mysql | MySQL 8.4、接続50、buffer pool512MB | mysql-data。3306非公開 |
| queue | 既存と同じmail/default、tries3、timeout30、max-time3600 | 常駐・自動再起動 |
| scheduler | 既存のschedule:work | 常駐・自動再起動 |
| mailpit | メール捕捉、最大500件／7日 | mailpit-data。SMTP/UIとも外部非公開 |
| backup | 起動時＋24時間ごとの圧縮DBバックアップ | db-backups。標準14日保存 |

すべてrestartはunless-stopped。DockerサービスのOS自動起動を有効化する。手動stopしたサービスは再起動後も停止状態なので、意図せず止めた場合は明示的にupする。healthcheckは状態表示であり、unhealthyだけで自動再起動する仕組みではない。監視と対応が必要。

業務仕様、モデル、既存migration、routes、料金、予約・保留・権利月・定期予定は変更しない。外部通知はMailpitに固定し、試験初期に実生徒へ送信しない。アプリ・MySQL・Mailpitはinternalネットワーク、Caddyのみ外向きネットワークにも参加する。

## 2. ARM64とビルド

PHP、MySQL、Node、Caddy、Composer、Mailpitは公開manifestにlinux/arm64を含むことを確認した。バージョンタグだけでなくmanifest digestを構成へ固定した。Oracle用サービスはlinux/arm64を明示している。

`docker/oracle/Dockerfile` はmulti-stage。Composer依存とNode依存をコンテナ内で新規インストールし、assetsを生成。Macのnode_modules/vendor/build成果物はコピーしない。実行イメージに開発依存、Node、npm、Composerを含めない。rolldownのnative optional dependencyを省略せず、Linux ARM64向けを取得する。

npmは基準環境と同じ11.6.2をビルド内で指定する。Nodeイメージ同梱の新しいnpmでは、既存lockのoptional peer依存に関してciの判定差があったため。アプリのpackage.jsonを変更する方針ではない。

Oracle上の実機検証は未実施。ローカルDocker Linux/ARM64での検証結果は末尾の検証記録を参照。Apple Siliconで起動することだけを根拠にOracle実機確認を省略しない。

## 3. 無料枠・容量・課金の確認

2026-10-10に確認したOracle公式資料では、Always FreeのA1は合計2 OCPU・12GB、boot/block volume合計200GB。home region内、既存リソースも含めた合計で確認する。旧4 OCPU・24GBを前提としない。

候補はUbuntu 24.04 LTS ARM64、A1 2 OCPU・12GB、Boot Volume100GB。100GB全体の中でOS・Docker・MySQL・uploads・バックアップを共有する。DBに100GBを専有させない。無料枠の表示・合計・見積もりに合わない場合は作成せず停止する。

**注意する設定**：A1の合計CPU/RAM超過、別リージョンのvolume、余分なboot volume、200GB超過、追加バックアップ、NAT Gateway、Load Balancer、Marketplace有料image、Object Storage／API利用量、転送量。無料トライアルのクレジットで一時的に無料でもAlways Freeとは限らない。予算アラートは課金の強制停止ではない。

空き容量不足なら、勝手に別の有料shapeを選ばず待つ。idle VMの回収もあるため、別保存先と復元手順が必要。

公式確認先：
- [Always Freeの資源と制限](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm)
- [Free Tier](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier.htm)
- [VM作成](https://docs.oracle.com/en-us/iaas/Content/Compute/Tasks/launchinginstance.htm)

## 4. 利用者がOracle管理画面で行う順序（ここから先は未実施）

1. Oracleへログインし、home regionと既存リソース／無料枠の使用状況を確認する。Limits/Quotas/UsageとBillingの表示も見る。
2. Identity & Security → Compartmentsで `musako-trial` 等の専用Compartmentを作る。以降はこのCompartmentを選択する。
3. Networking → Virtual Cloud NetworksでVCNを作る。例としてVCN10.20.0.0/16、Public Subnet10.20.1.0/24。既存ネットワークと重ならないことを確認する。
4. Internet Gatewayを作り、Public SubnetのRoute Tableへ0.0.0.0/0 → Internet Gatewayを設定する。今回NAT GatewayやLoad Balancerは不要。
5. 専用NSGを作り、stateful ingressをTCP22＝管理者の現在のPublic IPv4/32、TCP80/443＝0.0.0.0/0に設定する。VNICへNSGを割り当てる。SubnetのSecurity Listにも広いSSH許可等が残っていないか確認する（許可は合算される）。3306/8025/1025/5173/9000は許可しない。必要な戻り通信はstatefulに任せる。更新・証明書取得用のDNSとHTTP/HTTPS外向き通信を許可する。
6. Compute → Instances → Create instance。Compartmentを確認し、UbuntuのARM64 Platform Imageを選ぶ。Marketplaceの有料imageは使わない。
7. ShapeをVM.Standard.A1.Flex、2 OCPU・12GB以下（既存分を含む）にする。画面のAlways Free Eligible／無料対象表示と費用見積もりを確認。不明・有料表示ならここで停止する。
8. Boot Volumeは100GB案。合計無料枠と性能設定、暗号化設定、見積もりを再確認する。不要な追加volumeは作らない。
9. 作成済みVCN/Public Subnetを選び、Public IPv4を付与する。今回はIPv6を設定しない。SSH public keyだけを登録する。秘密鍵はアップロードしない。
10. 作成直前の全体見積もりを確認し、利用者が作成する。起動後にPublic IPv4・ARM64・選択容量を確認する。
11. 別ターミナルから `ssh -i 秘密鍵ファイル ubuntu@Public_IP` で接続する。初回ホスト鍵は正しいVMのものか確認する。
12. ドメイン確定後、DNSのAレコードをこのPublic IPv4へ向ける。誤ったAAAAレコードを残さない。ドメインとACME用連絡先を用意してから公開デプロイを進める。

管理画面のラベル配置は更新される場合がある。無料対象表示や見積もりを確認できない場合は作成しない。

## 5. Ubuntu・SSH・Firewall

Oracle VMに接続後に実施する。ローカルMacでは実行しない。

```bash
uname -m                       # aarch64
cat /etc/os-release
sudo apt-get update
sudo apt-get upgrade
sudo timedatectl set-timezone Asia/Tokyo
```

SSH鍵で接続できることを確認後、管理者が `/etc/ssh/sshd_config.d/00-musako.conf` に設定する。

```text
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
PubkeyAuthentication yes
```

`sudo sshd -t` で構文確認、`sudo sshd -T` で実効値を確認してから `sudo systemctl reload ssh`。**既存セッションを閉じず、別ターミナルで鍵ログイン成功を確認**する。接続元IPが変わる時はNSG/UFWの許可変更を先に行う。

UFWはSSH許可を先に入れる。以下の `<管理者Public_IP>` は置換する。

```bash
sudo ufw allow from <管理者Public_IP> to any port 22 proto tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw enable
sudo ufw status verbose
```

OracleのUbuntuイメージに既存iptablesルールがある場合は、`sudo iptables -S INPUT` 等で拒否ルールの順序も確認する。UFWの許可だけでは先行する拒否を解除できない場合がある。既存ルールの全消去はせず、SSHを維持して必要な許可を調整する。

Docker公開ポートはUFWを迂回するため、UFWだけで保護されると思わない。Composeの公開を80/443だけに固定し、OCI側でも制限する。専用VMで追加防御する場合はDockerのDOCKER-USERチェーンを使用する。ネットワークインターフェースとDockerのiptables backendを確認した管理者が適用し、SSHとHTTPSの疎通後に永続化する。他用途の既存ルールを上書きしない。

例（iptables backendの場合、IPv4。外向きIF名を実値へ置換）：

```bash
sudo iptables -I DOCKER-USER 1 -i <外向きIF> -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
sudo iptables -I DOCKER-USER 2 -i <外向きIF> -p tcp -m conntrack --ctorigdstport 80 -j RETURN
sudo iptables -I DOCKER-USER 3 -i <外向きIF> -p tcp -m conntrack --ctorigdstport 443 -j RETURN
sudo iptables -I DOCKER-USER 4 -i <外向きIF> -j DROP
```

Docker公式のUbuntu手順でDocker EngineとCompose pluginを導入する。ARM64対応の公式APTリポジトリを使用し、リモートDocker APIは公開しない。

- [Docker公式Ubuntu導入](https://docs.docker.com/engine/install/ubuntu/)
- [DockerとFirewallの注意](https://docs.docker.com/engine/network/packet-filtering-firewalls/)

```bash
sudo systemctl enable --now docker
sudo docker version
sudo docker compose version
```

以降の手順は `docker` を実行できる管理者で行う。dockerグループはroot相当の権限なので試験利用者を追加しない。

## 6. 初回デプロイ

**今回の変更はまだGitHubへpushしていない。別途承認してpushした後、Oracle側でこの構成を含むmainを取得する。** ドメイン未確定・DNS未反映ならweb公開はここで保留する。

```bash
git clone https://github.com/x6tdhbfsms-lgtm/musako-drum.git
cd musako-drum
git switch main
git pull --ff-only origin main
git status --short
git rev-parse HEAD
umask 077
cp docker/oracle/env.example .env.oracle
chmod 600 .env.oracle
```

Oracle上で専用APP_KEYとDB用の別々のパスワードを生成する。以下は空欄の初回ファイルのみを対象にし、既存キーは回転させない。

```bash
python3 <<'PY'
from pathlib import Path
import secrets, base64
p = Path('.env.oracle')
s = p.read_text()
values = {
 'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
 'ORACLE_DB_PASSWORD': secrets.token_hex(32),
 'ORACLE_DB_ROOT_PASSWORD': secrets.token_hex(32),
}
for key in values:
    if f'\n{key}=\n' not in s:
        raise SystemExit('空の初回設定ファイルではありません。キーを上書きしません。')
for key, value in values.items():
    s = s.replace(f'\n{key}=\n', f'\n{key}={value}\n')
p.write_text(s)
p.chmod(0o600)
print('Oracle専用のキーを生成しました。値は表示しません。')
PY
nano .env.oracle
```

ORACLE_DOMAINにドメイン名のみ（https://、パス、ポートなし）、ACME_EMAILに連絡先、ORACLE_RELEASEに今回のGit SHAを設定。APP_URLはComposeで `https://ドメイン` から生成する。ローカルAPP_KEYを流用しない。`.env.oracle` とAPP_KEYの安全な別保管が必要。

```bash
docker/oracle/compose.sh config --quiet  # 通常のconfigは秘密値も表示するので使わない
docker/oracle/compose.sh build app web   # Composer no-devとnpm ci/buildを含む
docker/oracle/compose.sh up -d mysql mailpit backup
docker/oracle/compose.sh ps
docker/oracle/compose.sh exec -T backup bash /opt/musako/backup.sh once
docker/oracle/compose.sh run --rm app php artisan migrate --force
docker/oracle/compose.sh up -d app queue scheduler web
docker/oracle/compose.sh ps
```

起動時に各PHPコンテナでconfig:cache、route:cache、view:cacheを実行する。public/storageのリンクはイメージ内に作成済み。storageは永続volume。コードはbind mountしない。migration・seedは起動時に自動実行しない。

試験DBは空から開始する。既存会員・予約・8コマを勝手に移さない。初期マスタと初回管理者は、データ方針と対象を確認して別途設定する。既存の開発Seederにはデモ用定義もあるため、運用DBで一括seedしない。

`migrate:fresh`、`migrate:refresh`、安易な `migrate:rollback` は禁止。初回でもmigration前に空DBのバックアップを取る。

```bash
docker/oracle/compose.sh exec -T app php artisan migrate:status
docker/oracle/compose.sh exec -T app php artisan schedule:list
docker/oracle/compose.sh exec -T app php artisan queue:failed
curl -f https://<試験ドメイン>/up
curl -I http://<試験ドメイン>/login
```

HTTPがHTTPSへ転送され、証明書が正しく、HTTPSログインでSecure Cookieが付くことを確認。CaddyがACMEで証明書を自動発行・更新する。80/443とDNSが正しく到達できるまで発行できない。Caddyのdata volumeを削除しない。

[Caddyの自動HTTPS](https://caddyserver.com/docs/automatic-https)／[PHP-FPM連携](https://caddyserver.com/docs/caddyfile/directives/php_fastcgi)

## 7. 更新デプロイ

短時間の停止を許容する試験運用手順。前リリースSHA、イメージ、バックアップ名を記録し、動作中のコードとDBスキーマを不用意に混在させない。

1. 新しいコードをfetchし差分・migrationをレビューする。作業ツリーがcleanであることを確認する。
2. 旧環境でメンテナンス開始、queue/schedulerを停止し、処理中のジョブが正常終了するのを待つ。Webも停止する。
3. backupを一回実行し成功を確認。APP_KEYを含む設定の安全な退避、uploaded filesの退避も行う。
4. mainをfast-forward更新し、ORACLE_RELEASEを新SHAへ更新する。DB passwordとAPP_KEYは変えない。
5. app/webをbuild。失敗したら旧SHAと旧ORACLE_RELEASEに戻して旧サービスを起動する。
6. 一回限りのappコンテナでmigrate --force。失敗したら公開せず復旧判断へ進む。
7. appを新イメージで起動、queue:restartのシグナル、queue/scheduler/webを再作成する。各起動でキャッシュを再生成する。
8. メンテナンス解除、health、HTTPS、ログイン、Queue、Scheduler、料金・振替の操作を確認する。

```bash
docker/oracle/compose.sh exec -T app php artisan down
docker/oracle/compose.sh stop queue scheduler web
docker/oracle/compose.sh exec -T backup bash /opt/musako/backup.sh once
# 成功を確認し旧SHAとバックアップ名を記録
git fetch origin
git merge --ff-only origin/main
# .env.oracleのORACLE_RELEASEのみ新SHAへ更新
docker/oracle/compose.sh build app web
docker/oracle/compose.sh run --rm app php artisan migrate --force
docker/oracle/compose.sh up -d --force-recreate app
docker/oracle/compose.sh exec -T app php artisan queue:restart
docker/oracle/compose.sh up -d --force-recreate queue scheduler web backup
docker/oracle/compose.sh exec -T app php artisan up
docker/oracle/compose.sh ps
```

停止中の503でhealthcheckがunhealthyになることは想定内。解除後も戻らない場合は公開を続けずログ確認。APP_KEYの変更は暗号化済みデータやセッションへ影響するため、通常更新で変更しない。

## 8. Rollback

GitとDBは別々に扱う。旧イメージは検証完了まで削除しない。アプリだけ戻せるスキーマ互換性を確認した場合のみ、サービスを止めて `git switch --detach <記録した旧SHA>`、ORACLE_RELEASEを旧SHAに戻し、旧app/web/queue/schedulerを起動する。起動時に旧コードのキャッシュを作り直し、状態確認後にメンテナンス解除する。git reset --hardで未保存変更を消さない。

migration実行後にスキーマ非互換がある場合、コードだけ戻しても復旧しない。勝手にmigration rollbackしない。停止を維持し、新しい別DBへバックアップを復元して整合性を確認し、失われる更新範囲と切替先を管理者が判断する。障害DB／volumeを保存し、上書きしない。

## 9. 日次バックアップ・復元

backupは起動時と成功後24時間ごとにmysqldump（single-transaction、routines/events/triggersを含む）→gzip→検証→日付付きファイルへrename。失敗時は失敗ログを残し5分後再試行。DBはInnoDBが前提。dump中にDDL/migrationを並行実行しない。14日を超える世代を成功後に削除し、保持日は7〜30日で設定する。

```bash
docker/oracle/compose.sh logs --tail=50 backup
docker/oracle/compose.sh exec -T backup ls -lh /backups
docker/oracle/compose.sh exec -T backup bash /opt/musako/backup.sh once
# 実在するファイル名を指定。運用DBへ接続して復元しない。
docker/oracle/restore-check.sh musako_trial-YYYYMMDDTHHMMSSZ-XXXXXX.sql.gz
```

restore-check.shはネットワークなしの新規MySQLコンテナへ復元し、テーブル存在を確認後、その一時コンテナと匿名volumeのみ削除する。運用MySQLは上書きしない。バックアップに含まれる時点の重要な件数／料金等の業務検証は別途行う。

DB dumpだけではuploaded files、APP_KEY、証明書は復元できない。ファイルと設定も安全に別保管する。uploadsの例（メンテナンス中に実施）：

```bash
umask 077
mkdir -p backups
docker/oracle/compose.sh exec -T app tar -czf - -C storage app > "backups/uploads-$(date +%Y%m%dT%H%M%S).tar.gz"
```

### VM外への保管（方式のみ、今回は作成しない）

Oracle Object Storage等のprivate bucket、または管理者の別端末へコピーする。Object Storageを使う場合は無料容量・API・転送量・既存使用量を確認し、必要ならファイルを暗号化、IAMを対象bucketに限定する。公開bucketにしない。CLI credentialをGitに入れない。予算／見積もりが不明ならbucketを作成しない。

VMから管理者端末へ安全に取り出す例：VM上でumask077の作業フォルダへ `compose.sh cp backup:/backups/<ファイル名> backups/`、管理者端末からSCPで取得。取得したファイルのchecksumを比較し、別MySQLでrestore確認を行う。コピー後のVM作業ファイルは用途と保管方針を確認して処理する。

## 10. Queue・Scheduler・メール

```bash
docker/oracle/compose.sh ps queue scheduler
docker/oracle/compose.sh logs --tail=100 queue scheduler
docker/oracle/compose.sh exec -T app php artisan queue:failed
docker/oracle/compose.sh exec -T app php artisan queue:restart
docker/oracle/compose.sh exec -T app php artisan schedule:list
```

`jobs`の件数・最古created_at・attempts、failed_jobsの件数をDB内で確認する。payloadには個人情報が含まれるので不用意に表示しない。再送済みかを確認し、失敗原因を解消した後だけ `queue:retry <対象UUID>` を実行する。一括削除しない。

MySQL内部で件数を見る例：

```bash
docker/oracle/compose.sh exec mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u "$MYSQL_USER" "$MYSQL_DATABASE" -e "SELECT queue, COUNT(*) AS count, MIN(created_at) AS oldest, MAX(attempts) AS max_attempts FROM jobs GROUP BY queue; SELECT COUNT(*) AS failed_count FROM failed_jobs;"'
```

試験SMTPはMailpit固定。実SMTP切替は次工程で、許可宛先と誤送信対策を確認してから構成を変更する。Mailpitはrelay設定なし・外向きネットワークなし。

UIを見る場合はVMで `docker/oracle/compose.sh exec mailpit sh -c 'hostname -i'` で内部IPを確認し、管理者端末から `ssh -L 18025:<Mailpit内部IP>:8025 ubuntu@<VMのPublic_IP>`。端末のlocalhost:18025のみを利用する。8025をVMの外部ポートとして公開しない。コンテナ再作成で内部IPは変わる。

## 11. health・ログ・容量・メモリ

外部healthは既存 `/up`。debug=falseで機密値を表示しない。DB疎通はMySQL healthcheckで別確認する。appはFPM ping、webは内部HTTP→Laravel /up、workerはプロセス、backupは26時間以内の成功を確認する。プロセス確認だけでジョブの滞留を検出できるわけではない。

Docker json-fileは10MB×5世代／サービス。Laravelはwarning以上の日次ログ14世代。CaddyのアクセスログはURL内の申込・パスワード設定tokenを記録しないため無効、運用エラーはDockerログで確認する。例外ログやバックアップも個人情報を含み得るためアクセスを管理する。

```bash
docker/oracle/compose.sh logs --tail=100 web app queue scheduler backup
docker/oracle/compose.sh exec -T app sh -c 'ls -lh storage/logs'
df -h
docker system df
free -h
docker stats --no-stream
docker/oracle/compose.sh exec -T mysql du -sh /var/lib/mysql
docker/oracle/compose.sh exec -T app du -sh storage/app storage/logs
docker/oracle/compose.sh exec -T backup du -sh /backups
sudo journalctl -k --since today | grep -Ei 'oom|out of memory|killed process'
```

OOMKilledは `docker inspect --format '{{.State.OOMKilled}}' <対象コンテナ>` で確認。RAM不足なら不要プロセスと負荷を調査し、勝手に有料RAMへ拡張しない。ディスク80%を注意、90%を緊急の目安として監視し、ログ・backup・images・uploadsの増加を分けて確認する。`docker system prune --volumes`、Composeの`down -v`は運用環境で使わない。旧イメージを消す前にrollbackの必要性を確認する。

## 12. Secret管理と既知TODO

`.env.oracle*`、`.oracle/`、backupsはGit対象外。Docker buildから.env、鍵、DB、storage、ローカルvendor/node_modulesを除外する。秘密値はbuild argsに渡さない。`docker compose config`や`docker inspect`の全出力を共有しない。root/アプリ用DB passwordは別にする。APP_KEYとDB dumpは別の安全な保管先を用意する。

既存Git履歴の既知形式（秘密鍵、GitHub token、AWS access key、設定済APP_KEY、実.envファイル）の検査は実施したが、あらゆる独自形式の秘密を保証する検査ではない。

以下はOracle対応に混ぜず別TODOとして保持する。
- 旧予約ID2のstudio fee／entitlement month：管理者による履歴確認が必要。今回補正しない。
- 料金表DB一意制約の追加：別途設計・migration・競合テストが必要。今回追加しない。
- 生徒の休退会API、通常電子ドラム開始分の制約、実機UI確認等、既存監査で残した事項。
- 任意fontaine未導入のbuild警告。

## 13. Oracle実機の受入チェック（VM作成後・現在未実施）

- [ ] 作成画面で無料対象、home region、合計OCPU/RAM/volume、見積もりを確認
- [ ] uname -mがaarch64。すべてのimageがlinux/arm64
- [ ] ARM64上でDocker build、Composer no-dev、npm ci/buildが成功
- [ ] .env.oracle専用APP_KEY、debug無効、実データ未コピー
- [ ] migrate前backup成功、migrate --force、config/route/view cache成功
- [ ] MySQL接続・永続化、再作成でもデータ維持、3306外部非公開
- [ ] HTTPSの正規証明書、HTTP→HTTPS、更新、Secure Cookie、ログイン
- [ ] /upと各サービスhealth、内部port非公開
- [ ] 安全な試験通知がQueue→Mailpitへ届く。外部送信なし
- [ ] jobs/failed_jobs、queue:restart、Scheduler一覧・Asia/Tokyo
- [ ] 日次backup成功、失敗ログとhealth、別コンテナrestore成功
- [ ] VM外backup取得とrestore、APP_KEY／uploaded filesの保管
- [ ] VM再起動後にDocker・全サービス・HTTPSが復旧
- [ ] 実機で予約・電子30/45/60・studio60・移動・保留・翌月権利・料金・権限を確認
- [ ] 更新手順と旧リリースへの復帰を試験。DB rollbackを自動実行しない

## 14. リポジトリ準備の検証記録

別紙 `docs/oracle-cloud-verification.md` に、ローカルLinux/ARM64での実行結果、回帰テスト、確認できた範囲と未実施事項を記録する。
