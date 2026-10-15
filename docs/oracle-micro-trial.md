# Oracle E2 Micro: HTTP health-only試験起動

対象はmusako-drumのみ。基準アプリHEADは39acddbe14e90845ee9d522802acc5a680acf223。業務PHP・DB設計・既存データは変更しない。

## 構成と利用制限

Oracle Ubuntu24.04 x86_64、RAM約1GB、Swap2GBに対する暫定構成。A1用compose.oracle.yamlへcompose.oracle.micro.yamlを重ねる。起動・管理コマンドは必ず `docker/oracle/micro.sh` を使用する。通常の開発compose.yamlは使用しない。

- app/queue/scheduler/MySQL/web、必要に応じbackupのみ常駐。Mailpitはprofileに隔離して起動しない。
- メールはLaravel array transportで破棄する。外部送信・ログへの本文保存・メール受信確認は行わない。実ユーザーを登録しない。
- webはHTTP `/up` のGET/HEADのみ公開。その他の画面は503。証明書取得を行わない。
- APP_URLは既存entrypointのHTTPS制約を維持するため、暫定的にhttps://VMのIPを設定する。これはHTTPS接続可能を意味しない。実画面を使用する前にドメイン・DNS・正規HTTPS設定が必須。
- 公開Docker portは80だけ。3306、Mailpit、FPM等は内部のみ。SSH22は既存設定を維持。
- PHP FPMはondemand最大2、PHP memory96MB、OPcache32MB。MySQL buffer64MB、performance_schema無効、接続20。
- コンテナRAM上限：app160MB、queue128MB、scheduler128MB、MySQL384MB、web64MB、backup96MB。上限の合計はOSを含めると物理RAMを超えるため、Swapを含め監視する。高負荷の運用を保証しない。
- restart unless-stoppedを継承。VM再起動後にも復帰する設計。手動停止したサービスは明示的にupが必要。

## 初回配置

1. VMのCPU/RAM/Swap/disk/Docker確認、OOMがあれば停止する。
2. GitHub公式fingerprintを照合しDeploy Key認証を確認。読み取り専用鍵を使用し、PATへ勝手に変更しない。
3. /opt/musako-drumへmainをclone。HEAD不一致なら停止する。
4. 未pushのOracleインフラファイルだけをMacから転送する。秘密鍵やMacの.env、DB、storageは転送しない。
5. `.env.oracle` はVMで専用APP_KEYとランダムな別々のDB passwordを生成してmode600で保存する。Git対象外。ORACLE_DOMAINは暫定IP、ACME_EMAILは未使用のplaceholder。秘密値を表示しない。
6. RAMの多い別マシンでDockerfileをlinux/amd64向けにbuildする。app/webの2imageを `docker image save | gzip | ssh ... 'gzip -dc | docker image load'` で転送する。GitHubへのpushは不要。画像アーキテクチャとsource commitを必ず確認する。
7. 専用volumeがまだ存在しないことを確認。既存volumeがあれば削除せず調査する。

```bash
docker/oracle/micro.sh config --quiet
docker/oracle/micro.sh up -d --wait mysql
docker/oracle/micro.sh --profile backup up -d backup
docker/oracle/micro.sh exec -T backup bash /opt/musako/backup.sh once
docker/oracle/micro.sh run --rm app php artisan migrate --force --no-interaction
docker/oracle/micro.sh up -d --wait app queue scheduler web
```

migrate:fresh / migrate:refresh / seedは使用しない。migrationとキャッシュ生成の完了を確認する。初期管理者・マスタ・会員・確認用8コマは今回作成・移行しない。

## 確認・監視

```bash
free -h
swapon --show
df -h /
docker stats --no-stream
docker/oracle/micro.sh ps
docker/oracle/micro.sh exec -T app php artisan migrate:status
docker/oracle/micro.sh exec -T app php artisan schedule:list
docker/oracle/micro.sh exec -T app php artisan queue:failed
docker/oracle/micro.sh logs --tail=50 app web queue scheduler backup
sudo journalctl -k --since today | grep -Ei 'oom|out of memory|killed process'
```

OOMKilled、Swapの急増、利用可能RAM枯渇、サービス再起動の反復があれば負荷を加えず停止して報告する。1GBのVMで全回帰テスト・並列ビルド・2つ目のMySQLを無理に起動しない。

外部HTTP `/up` 200、`/login` と `/` 503を確認する。OCIのSecurity List/NSGは管理画面で22/80/443の許可と22の接続元制限を確認する。Docker公開portはホストINPUT/UFWを迂回するため、INPUTルールだけで制限できると考えない。既存SSHのルールを変更・削除しない。

## バックアップ・復元

backup profileを明示して起動すると毎日gzip、標準14日（7〜30日）保存。DBと別volumeに保存するが、同じVM障害では失われるためSCP等のVM外保管も必要。成功・失敗はbackupログで確認。

復元確認はRAMの多い別環境で実施する。x86_64では `ORACLE_RESTORE_PLATFORM=linux/amd64 docker/oracle/restore-check.sh <backup名>`、ARM64では既存の既定値を使用する。ネットワークなしの一時MySQLにのみ復元し、運用DBを上書きしない。ファイルとAPP_KEYも別途安全に保管する。

## ドメイン決定後・A1への移行

ドメイン決定前にHTTPS証明書を取得しない。HTTPの制限を解除して管理画面を利用することは禁止。

A1確保後は新VMで無料対象・ARM64・6GB以上のRAM・Dockerを確認。移行中は旧側のweb/queue/schedulerを止め、DB・uploads・APP_KEYを安全に退避する。新VMで同じアプリcommitをARM64向けにbuildし、独立DBへ復元する。既存APP_KEYは移行時に維持し、旧DBは削除しない。ドメイン/DNSとCaddyのHTTPSを設定し、Queue/Schedulerを二重稼働させず切り替える。料金・権利月・振替等の受入確認後に利用開始する。

今回のmicro overlayはamd64固定・HTTP制限付きなのでA1には使用しない。A1で既存compose.oracle.yamlを用いる場合も合計メモリとMySQL設定を調整し、外部メール切替は別途判断する。詳細な更新・rollback・VM外backupはoracle-cloud-preparation.mdを参照。

## 2026-10-11 実施結果

- Oracle VM /opt/musako-drumにmainをclone。HEAD 39acddbe14e90845ee9d522802acc5a680acf223とDeploy Keyの対象を確認。GitHubへpushしていない。
- Macでlinux/amd64向けapp/webをbuildして転送。アプリコード・依存lock・開発Composeに差分なし。
- 専用の新規DBに34 migration適用済み。users=0、reservation_requests=0。既存DB・確認用8コマは移行・変更していない。
- 外部HTTP /up=200、/login=503。まだ会員向け利用は不可。ドメイン確定前なので正規HTTPS未設定。
- production/debug=false/Asia/Tokyo、database queue、array mailを実機確認。
- 合成通知1件でjobs 1→0、failed_jobs=0。外部送信なし。
- app/web/mysql/queue/scheduler/backupがhealthy。Mailpit/Viteは未起動。
- DB永続volume、日次backup稼働、migration前後のgzip取得に成功。1GB VM上で別MySQLを併走させるrestoreテストは実施しない。
- OSは既存iptablesを維持（SSH許可・その他INPUT拒否）、UFWは未導入。Docker公開portは80のみ。OCI管理画面のルール内容は未確認だが、外部からHTTP疎通成功。
- 全回帰はVMに負荷を掛けずMacの隔離Dockerで実行：MySQL320 passed/1,392 assertions、SQLite316 passed+4 skipped/1,371 assertions、Pint317 files passed。
- npm ci/buildはx86_64イメージ内で成功。Composer no-dev/最適化autoload/対応extensions確認、実機config/route/view cache成功。Composer audit/npm audit脆弱性0件。
- VM再起動そのものは未実施。Docker active/enabledと各サービスunless-stoppedを確認する。起動後の実測メモリ・最終Git状態は完了報告を参照。

既知のreservation ID2、料金表一意制約、休退会API、通常電子ドラム開始分制約は未変更。次工程はドメイン/DNS/HTTPS、その後に初期管理者・マスタ・試験データの方針確認。

起動時の修正：app/queue/schedulerが共有のBlade cacheを同時に消去・生成すると、filemtimeエラーで一度再起動する競合を実機で確認した。compose.oracle.yamlのPHP共通設定で `storage/framework/views` をコンテナ別tmpfs（最大16MB、www-data所有）に分離する。uploads/ログ/その他storageは引き続き永続volumeを使用し、業務データは変更しない。

## 2026-10-15 再確認

キャッシュ分離後、app/schedulerは3日以上healthyで再起動0回、OOMなし。Queueはmax-time=3600による予定どおりの1時間ごとの終了・再起動を確認（90回）。pending jobs=0、failed_jobs=0。34 migration適用済み、users=0、reservations=0を維持。10月12・13・14日の日次backup成功。外部/up=200、/login=503。

RAM使用541MiB/954MiB、利用可能412MiB、Swap使用332MiB/2GiB、disk使用6.7GB/45GB・空き38GB（確認時点）。公開Docker portは80のみ、MySQL公開なし。envはmode600・Git対象外。カーネルログにOOM該当なし。VM再起動試験・HTTPS設定・実データ登録は未実施。

VMのGit HEADは基準39acddbを維持し、Oracleインフラ設定は作業ツリーの差分として配置している。Mac側ではインフラ変更をローカルcommitして保管し、GitHubへpushしていない。ドメイン確定まではHTTP healthだけを提供する。
