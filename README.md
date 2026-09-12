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
- SQLiteを使った自動テスト

契約回数に応じた月間予約数の自動制限は次段階で実装します。カード番号や口座番号を保存するカラムは設けていません。将来の決済連携でも、外部決済サービス側で機密情報を管理する方針です。

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

## 契約内容変更の適用日

契約変更を承認すると、元の契約行を上書きせず、希望適用日から有効になる新しい契約履歴を作成します。元の契約は希望適用日の前日まで有効です。そのため、将来日までは現在の契約が表示され、適用日以降は新しい契約が予約条件の参照対象になります。

すでに作成済みの予約が参照している契約履歴は変更しません。過去の契約・予約・申請履歴も削除しません。

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
- `lesson_enrollments`: 生徒とコースの契約。月2回／月4回等の上限判定に使用予定
- `lesson_slots`: 先生が公開する日時枠
- `reservation_requests`: 生徒の申請、先生・管理者の審査、取消履歴
- `attendance_notices`: 予約ごとのお休み・遅刻連絡。重複登録せず更新履歴日時を保持
- `transfer_requests`: 元予約、希望枠、振替後予約、審査結果を保持する振替履歴
- `membership_status_requests`: 休会・退会・再開の希望日、審査、適用日時、適用前の在籍状態を保持
- `contract_change_requests`: 時間割、回数、時間、コース、会場の変更前後、希望適用日、審査履歴
- `personal_information_change_requests`: 氏名・連絡先・住所の変更前後と審査履歴
- `payment_method_change_requests`: 希望する支払い方式と審査履歴。カード・口座情報は保持しない
- `inquiries`: 問い合わせ本文と `open` / `in_progress` / `resolved` の対応状況

予約と振替の満席判定・二重申請防止、在籍申請の重複防止には、DB制約に加え、同時申請・同時承認による超過を防ぐDBトランザクションと行ロックを使用しています。
