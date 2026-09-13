# MUSAKOドラム教室 Webシステム

studio34とは完全に分離した、MUSAKOドラム教室専用のLaravelプロジェクトです。

## 現在の実装範囲

- メールアドレス・パスワードによるログイン／ログアウト
- `student`（生徒）、`teacher`（先生）、`admin`（管理者）の権限分離
- 生徒・先生プロフィール
- コース、会場、在籍契約（契約月回数・レッスン時間）
- 生徒マイページと、スマートフォン対応の空き枠カレンダー
- 先生・管理者ダッシュボード
- 先生による予約枠の作成・編集・削除
- 生徒の予約申請（承認待ち）、予約一覧、キャンセル
- 先生・管理者による予約申請の承認・却下
- 同一生徒の重複申請と、定員を超える承認を防ぐ排他制御
- 承認済み予約に対するお休み・遅刻連絡（連絡内容の更新に対応）
- 前日19:00締切の振替申請、承認・却下、元予約と振替先予約への反映
- 休会・退会・再開申請、承認・却下、在籍契約への即日／予約反映
- 時間割、月回数、レッスン時間、コース、会場の変更・追加申請
- 承認済み変更を適用日から有効にする期間付き契約履歴
- 氏名、メールアドレス、電話番号、住所の変更申請と変更前後の履歴
- 支払い方法変更申請（カード番号・口座番号は保存しない）
- 生徒のお問い合わせ履歴と、先生・管理者による対応状態管理
- 先生・管理者向けの生徒詳細（契約、予約、振替、出欠、各種申請履歴）
- 契約履歴の適用日を考慮した月間レッスン回数サマリー
- 実施済み・確定・申請中・欠席・キャンセル・振替を区別した回数管理
- 生徒予約の月間上限チェックと、先生・管理者による理由付き上限超過承認履歴
- 月をまたぐ振替を元レッスンの月へ1回だけ計上するレッスン権利月
- 通常／フレックスと一般／ジュニアを組み合わせた、適用日付き料金履歴
- 料金履歴からレッスン料・フレックス加算・スタジオ代を算出する共通サービス
- 管理者向け料金・入会案内設定と、先生向け読み取り専用の料金履歴
- 公開料金ページと、生徒・先生画面の現在契約に応じた料金目安
- 予約承認時のスタジオ代スナップショットと、振替時の二重請求防止
- 通常／フレックス、一般／ジュニアの契約変更申請と適用日付き履歴
- フレックス契約で2か月以上予約がない生徒の先生向け注意表示
- 予約・振替・在籍・契約・個人情報・支払い方法・問い合わせのメール通知
- お休み／遅刻連絡と予約キャンセルの先生・管理者向け通知
- Asia/Tokyo基準の予約前日リマインダーと二重送信防止履歴
- 先生ダッシュボードの「本日のレッスン」一覧
- Database Queue対応と、ローカルメール確認用Mailpit
- SQLiteを使った自動テスト

カード番号や口座番号を保存するカラムは設けていません。将来の決済連携でも、外部決済サービス側で機密情報を管理する方針です。

## 必要なもの

- Docker Desktop
- Git

PHPやMySQLをPCへ個別に入れる必要はありません。

## 初回起動

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

ブラウザで <http://localhost:8081> を開きます。MySQLはPC側の `33061` 番ポートを使うため、一般的な `3306` 番ポートを使う別案件と衝突しにくい構成です。

開発用メールは外部へ送信されず、Mailpitの <http://localhost:8025> で確認できます。

停止:

```bash
./vendor/bin/sail down
```

DBデータも消す `sail down -v` は、必要性を確認せず実行しないでください。

## 将来日が指定された在籍申請の反映

承認済みの休会・退会・再開申請は、希望日の午前0:05（Asia/Tokyo）に在籍契約へ反映するスケジュールです。ローカルでスケジュールを動かす場合は、別のターミナルで次を実行します。

```bash
./vendor/bin/sail artisan schedule:work
```

本番環境ではLaravelのスケジューラを毎分実行するcron設定が必要です。

## メール通知とQueue

通知メールはLaravel Notificationで作成し、`mail` Queueへ登録します。予約承認などのDBトランザクション完了後に通知を登録し、通知のキュー登録に失敗しても予約・申請のDB処理は取り消しません。メール送信エラーはQueue側で最大3回再試行され、最終的な失敗はLaravelの `failed_jobs` で確認できます。

ローカルでQueue workerを起動するには、別のターミナルで次を実行します。

```bash
./vendor/bin/sail artisan queue:work --queue=mail,default --tries=3 --timeout=30
```

コードやメール設定を変更した後は、workerへ反映するため次を実行します。

```bash
./vendor/bin/sail artisan queue:restart
```

現在の通知対象は次のとおりです。

- 予約申請：担当講師、管理者
- 予約承認・却下：申請した生徒
- 生徒による予約キャンセル：担当講師、管理者
- お休み・遅刻連絡の登録／更新：担当講師、管理者
- 振替申請：元予約・振替先の担当講師、管理者
- 振替承認・却下：申請した生徒
- 休会・退会・再開申請：担当講師、管理者
- 休会・退会・再開の承認／却下：申請した生徒
- 契約内容変更、個人情報変更、支払い方法変更の申請：担当講師、管理者
- 各変更申請の承認／却下：申請した生徒
- 新しい問い合わせ：担当講師、管理者
- 問い合わせが解決済みになったとき：問い合わせた生徒
- 承認済み予約の前日リマインダー：予約した生徒

ユーザーの `notification_preferences` が未設定の場合は全通知ONです。将来、`email`、`reservation`、`attendance`、`transfer`、`procedure`、`inquiry`、`reminder` ごとにON/OFF画面を追加できる構造です。メールアドレスが未登録または不正なユーザーは、安全に送信対象から除外します。

個人情報変更メールに変更前後の氏名・メール・電話番号・住所は記載しません。支払い方法変更メールにもカード番号、口座番号、備考は記載しません。

### ローカルのMailpit

`.env.example` の標準設定ではSMTP送信先がDocker内のMailpitです。

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=info@musako-drum.local
```

`./vendor/bin/sail up -d` でMailpitも起動します。Web UIは <http://localhost:8025> です。`FORWARD_MAILPIT_PORT` を変更すればPC側の確認ポートを変更できます。

### 本番SMTP

本番環境では `.env` またはホスティング側のSecretへ実際のSMTP情報を設定します。値をGitへcommitしないでください。

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-user
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=info@example.com
MAIL_FROM_NAME="MUSAKOドラム教室"
QUEUE_CONNECTION=database
```

設定後は `php artisan config:cache` とQueue workerの再起動が必要です。

## 前日リマインダーとScheduler

毎日18:00（Asia/Tokyo）に、翌日の承認済み予約を抽出してリマインダーを `mail` Queueへ登録します。時刻は `.env` の次の値で変更できます。

```dotenv
LESSON_REMINDER_TIME=18:00
```

予約ごとに `lesson_reminder_deliveries` の一意な履歴を作るため、Schedulerが再実行されても同じ予約へ二重送信しません。送信ジョブにも一意ロックがあり、送信成功日時・失敗日時・試行回数を保存します。

本番サーバーでは、Laravel Schedulerを毎分呼び出します。

```cron
* * * * * cd /path/to/musako-drum && php artisan schedule:run >> /dev/null 2>&1
```

複数台構成では共有可能なdatabaseまたはRedisのキャッシュを使ってください。リマインダーと在籍申請反映は `onOneServer()` と `withoutOverlapping()` を使用しています。ローカルでは次のコマンドでSchedulerを継続実行できます。

```bash
./vendor/bin/sail artisan schedule:work
```

## 契約内容変更の適用日

契約変更を承認すると、元の契約行を上書きせず、希望適用日から有効になる新しい契約履歴を作成します。元の契約は希望適用日の前日まで有効です。そのため、将来日までは現在の契約が表示され、適用日以降は新しい契約が予約条件の参照対象になります。

すでに作成済みの予約が参照している契約履歴は変更しません。過去の契約・予約・申請履歴も削除しません。

## 月間レッスン回数の数え方

- 実施済み、未来の確定予約、申請中予約、生徒都合の欠席はそれぞれ1回分として扱います。
- キャンセルと却下は回数を消化しません。
- 承認済み振替は、元予約をキャンセルしたうえで振替先だけを1回分として扱います。
- 振替先が翌月でも、予約に保存した `lesson_entitlement_month`（レッスン権利月）を使い、元レッスンの月に計上します。
- 契約回数の変更が月途中から有効になる場合、その月内で最後に有効になる契約履歴の回数を月間上限にします。コース追加は独立した契約として合算します。
- 生徒の新規申請は、確定・申請中・実施済みの合計が上限に達すると受け付けません。同時申請時は生徒行をロックして超過を防ぎます。
- 先生・管理者が例外的に超過承認する場合は、明示的な指定と理由が必要で、処理者・日時・理由を予約履歴へ保存します。

既存予約で `lesson_entitlement_month` が未設定の場合は、通常予約では予約枠の月、振替予約では元予約枠の月を自動的に参照します。

## 料金管理

料金はController、Blade、計算サービスへ直接書かず、`price_rates` の適用日付き履歴から参照します。過去の料金行は更新せず、新しい適用日の行を追加するため、料金改定後も過去日時点の計算結果を再現できます。

初期データとして、2026年9月1日適用の次の料金を登録します（すべて税込）。

| 区分 | 月1回 | 月2回 | 月3回 | 月4回 |
| --- | ---: | ---: | ---: | ---: |
| 一般・通常 | 6,000円 | 11,000円 | 16,500円 | 22,000円 |
| ジュニア・通常 | 5,500円 | 10,000円 | 15,000円 | 20,000円 |

- フレックス加算: 月額500円
- スタジオ代: 1レッスン1,610円

`LessonPricingService` が契約区分、料金区分、月回数、対象日から料金を算出します。該当する履歴がない組み合わせは0円とみなさず「要相談」と表示します。

スタジオ代は予約承認時に予約へスナップショット保存します。振替承認時は元予約から振替先予約へ金額を移し、元予約と振替先を二重に請求しない構造です。既存予約のスナップショットがない場合だけ、元レッスン日時点の履歴から補います。

料金・入会案内の変更は管理者だけが行えます。先生は履歴を閲覧できますが変更できず、生徒は管理画面へアクセスできません。公開料金ページは設定履歴により、次の表示を切り替えられます。

- `current_prices`: 現在料金を表示
- `example_prices`: 料金例として表示
- `external_only`: システム内の金額表を隠し、公式料金ページへの案内だけを表示

入会金、キャンペーン料金・説明、料金注意書き、公式レッスン料金URL、公式スタジオ料金URLも適用日付き設定として管理します。カード番号や銀行口座番号などの決済情報は料金機能でも保存しません。

通常レッスンは固定曜日・開始時刻を持ち、フレックスは固定日時を持たない契約として扱います。通常／フレックスと一般／ジュニアの変更は契約変更申請で行い、承認前の契約は変更しません。承認後も元の契約履歴を残し、希望適用日以降だけ新しい契約を参照します。

## 支払い情報の取り扱い

- 入力・保存するのは希望する支払い方法と申請管理情報だけです。
- カード番号、セキュリティコード、銀行口座番号は入力・保存しません。
- 備考欄にもカード番号や口座番号を記載しないでください。
- 将来のオンライン決済は、決済代行サービスの安全な入力画面へ接続します。

## テスト

```bash
./vendor/bin/sail artisan test
```

ホスト側に対応するPHPとComposerがある場合は `php artisan test` でも実行できます。テストはインメモリSQLiteを使い、開発用MySQLのデータを変更しません。

## 別PCで開発を再開する

GitHubへPrivateリポジトリとして登録した後、別PCで次の流れを使います。

```bash
git clone <GitHubのリポジトリURL> musako-drum
cd musako-drum
cp .env.example .env
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v "$(pwd):/var/www/html" \
  -w /var/www/html \
  laravelsail/php85-composer:latest \
  composer install --ignore-platform-reqs
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
./vendor/bin/sail artisan test
```

WindowsではWSL2のUbuntu内で同等の手順を実行してください。

## Git運用と秘密情報

- `.env`、実データ、パスワード、APIキーはGitHubへ登録しません。
- 共有する設定項目は値を伏せて `.env.example` に追加します。
- 作業開始時に `git pull`、終了時にテスト後 `git add`、`git commit`、`git push` を行います。
- 本番DBをローカルへコピーする場合は、個人情報の取り扱いルールを別途定めます。

## DB設計

- `users`: ログイン情報と権限
- `student_profiles` / `teacher_profiles`: 役割固有の情報
- `courses`: 個人・グループ、標準時間、標準月回数、定員
- `venues`: 会場。予約枠から参照するため会場追加・変更に対応可能
- `lesson_enrollments`: 生徒とコースの期間付き契約。月2回／月4回等の上限判定に使用
- `price_rates`: 通常料金、フレックス加算、スタジオ代の区分・月回数・適用日付き履歴
- `pricing_settings`: 料金表示モード、入会金、キャンペーン、注意書き、公式URLの適用日付き履歴
- `lesson_slots`: 先生が公開する日時枠
- `reservation_requests`: 生徒の申請、先生・管理者の審査、取消履歴、レッスン権利月、実施日時、上限超過承認履歴、承認時のスタジオ代
- `lesson_reminder_deliveries`: 予約リマインダーの対象日、キュー登録、送信成功・失敗、試行回数。予約ごとに一意
- `attendance_notices`: 予約ごとのお休み・遅刻連絡。重複登録せず更新履歴日時を保持
- `transfer_requests`: 元予約、希望枠、振替後予約、審査結果を保持する振替履歴
- `membership_status_requests`: 休会・退会・再開の希望日、審査、適用日時、適用前の在籍状態を保持
- `contract_change_requests`: 時間割、回数、時間、コース、会場の変更前後、希望適用日、審査履歴
- `personal_information_change_requests`: 氏名・連絡先・住所の変更前後と審査履歴
- `payment_method_change_requests`: 希望する支払い方式と審査履歴。カード・口座情報は保持しない
- `inquiries`: 問い合わせ本文と `open` / `in_progress` / `resolved` の対応状況

`users.notification_preferences` は将来のユーザー単位通知設定に使用します。メール未登録ユーザーを安全に扱うため、`users.email` はnullableですが、通常のログインユーザー登録・個人情報変更では引き続きメールアドレスを必須として扱います。

予約と振替の満席判定・二重申請防止、月間回数上限、在籍申請の重複防止には、DB制約に加え、同時申請・同時承認による超過を防ぐDBトランザクションと行ロックを使用しています。
