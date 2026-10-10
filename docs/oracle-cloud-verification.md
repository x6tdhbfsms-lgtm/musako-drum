# Oracle試験運用準備の検証記録

実施日：2026-10-10。基準HEAD：39acddbe14e90845ee9d522802acc5a680acf223。

## 結論と範囲

リポジトリ側の準備とローカルLinux ARM64での検証は完了。Oracle VM作成前で停止する。Oracle上のリソース、DNS、正規HTTPS証明書、実機再起動は未実施。GitHubへpushしていない。

業務アプリ、routes、migration、料金、既存開発compose、package.json／package-lock.json、composer.json／composer.lockは変更していない。既存開発DBと10月10・11日の8コマは対象外。別のstudio34リポジトリは操作していない。

今回の検証用Composeプロジェクトはmusako-oracle-check。専用volume、専用ランダムAPP_KEY／DB passwordを使用し、既存の停止中の開発コンテナは起動していない。MySQL全テストはさらに別DB musako_oracle_regressionで実施した。HTTP/HTTPSは127.0.0.1の18081/18443だけへ公開した。

## 変更ファイル

| ファイル | 目的 |
|---|---|
| compose.oracle.yaml | 開発環境と独立した7サービス、内部network、永続volume、再起動・health・ログ設定 |
| docker/oracle/Dockerfile | Linux ARM64、Composer no-dev、npm ci/build、PHP-FPM/Caddyのmulti-stage build |
| docker/oracle/Caddyfile | HTTPS自動発行更新、HTTP転送、内部health、秘密ファイルと公開storage内PHPの遮断 |
| docker/oracle/php.ini、fpm.conf | エラー非表示、OPcache、プロセス・メモリ設定 |
| docker/oracle/entrypoint.sh | production/HTTPS確認、config/route/view cache、migrationは自動実行しない |
| docker/oracle/compose.sh | 専用envファイルとプロジェクト名を明示 |
| docker/oracle/env.example | 秘密値が空欄のOracle専用設定雛形 |
| docker/oracle/backup.sh | 毎日gzip backup、14日保持（7〜30日指定）、失敗ログ・再試行 |
| docker/oracle/restore-check.sh | 別のネットワーク隔離MySQLへ復元し、一時コンテナだけを終了・削除 |
| .dockerignore、.gitignore | 秘密値・DB・backup・開発依存をbuild/Gitから除外 |
| .env.example、README.md | 環境変数名・専用手順への案内 |
| docs/oracle-cloud-preparation.md | Oracle画面、SSH・Firewall、初回・更新・復旧・監視の日本語手順 |
| docs/oracle-cloud-verification.md | 本記録 |

## 回帰・品質検証

| 検証 | 結果 |
|---|---|
| 全PHPUnit（SQLite） | 316 passed、MySQL専用4 skipped、1,371 assertions |
| Docker MySQL全PHPUnit | **320 passed、1,392 assertions** |
| Laravel Pint --test | 317 files passed |
| ホスト npm ci / npm run build | 成功 |
| Linux ARM64 npm ci / npm run build | 成功（新規node_modules、npm11.6.2） |
| Composer --no-dev、最適化autoload、platform requirements | ARM64イメージ内で成功 |
| Laravel config / route / Blade cache | 実行用イメージで全て成功 |
| Composer audit | 脆弱性0件 |
| npm audit | 脆弱性0件 |
| Compose config --quiet | 成功 |
| Caddy validate | 公開用設定・確認用設定とも成功 |
| シェル構文 | 成功 |
| git diff --check | 成功 |

同梱npm11.19.0では既存lockのoptional peer React依存の判定差によってnpm ciが失敗した。Oracle buildで基準環境と同じnpm11.6.2を使用することで解消した。アプリの依存定義・lockの変更は不要だった。fontaineの任意機能に関する既存警告は残るが、buildは正常終了した。

## ARM64構成・稼働検証

PHP 8.5 FPM、Node24、Composer2、MySQL8.4、Caddy2、Mailpitの全ベースイメージでlinux/arm64 manifestを確認し、digestを固定した。PHP実行イメージはlinux/arm64、PHP8.5.11。Macのnode_modules/vendorは使用せずDocker内で依存を取得した。

- 全7サービスがhealthy。MySQL/Mailpit/worker等に公開ポートなし。
- Oracle用APP_ENV=production、APP_DEBUG=false、Asia/Tokyo、暗号化Secure Session設定を確認。
- 専用空DBへmigrate --force成功。起動時の自動migration/seedなし。
- HTTPS /login＝200、/up＝200、HTTP＝308転送、Secure Cookieを確認。
- HTTPSは検証専用ローカルCAを信頼して確認した。Let's Encrypt実発行・更新は未実施。
- /.env＝404。public storageの通常ファイルは表示、PHPファイルは404。
- 実行イメージに実.env／.env.oracle／.git／node_modules／PHPUnitなし。
- MySQL再作成前後で試験セッション1件を維持。既存開発volumeは使用していない。
- 意図的なMySQL停止中はworkerが接続エラーで再起動し、MySQL復帰後に回復。queue:restartでも再起動を確認。
- 既存通知クラスを使いexample.invalid宛の合成通知を投入。jobs 1→0、failed_jobs 0、Mailpit1件。外部SMTP送信なし。
- Scheduler一覧の4処理を維持。billingコマンドの定期実行成功を確認。
- 小規模・空DBでのアイドル使用量は全体約624MiB。実運用の負荷試験やOracle実機の必要メモリを保証する数値ではない。

## Backup・復元・秘密情報

- 日付付きgzip backup成功。7〜30日の範囲チェックを実装。
- 誤った認証で意図的に失敗させ、失敗ログと未完成ファイルの除去を確認。
- 保存期限を超える検証用ファイルを作成し、成功後の世代削除を確認。
- network=noneの別MySQLコンテナで復元成功、41テーブルを確認。運用DBへ上書きしていない。
- 復元用の一時パスワードは値を表示せず生成し、終了時に削除する。
- VM外backupはSCPまたはprivate Object Storageの方式・確認事項を手順化。外部リソースは作成していない。
- 既存Git履歴728個の重複しないblobを検査し、既知形式の秘密鍵/GitHub token/AWS access key/実APP_KEY/実.envの明らかな混入は0件。任意形式を網羅する保証ではない。

## 残項目

今回確認した範囲では、Oracle準備に起因する新たなCritical/Highは検出していない。ただし公開運用開始の確認は未完了である。

1. 利用者によるOracle無料枠確認・VM作成。実機build/HTTPS/Queue/Scheduler/backup/restore/再起動検証。
2. 試験ドメインとDNS、ACME連絡先の確定。正規証明書と自動更新の実機確認。
3. 初期マスタ・初回管理者・試験データの範囲決定。既存データの自動移行なし。
4. VM外backup保存先・復元運用の確定。Mailpitから実SMTPへの切替は後工程。
5. 旧予約ID2のstudio fee／entitlement month管理者確認、料金表DB一意制約、既存Lowは別TODOのまま。今回修正していない。

最終Git状態とローカルcommit IDは完了報告に記載する。公開・pushは別途利用者の指示を待つ。
