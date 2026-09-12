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
- SQLiteを使った自動テスト

連絡先・コース等のその他変更申請や、契約回数に応じた月間予約数の制限は次段階で実装します。カード番号や口座番号を保存するカラムは設けていません。将来は決済代行サービスの顧客ID等だけを保持します。

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

予約と振替の満席判定・二重申請防止、在籍申請の重複防止には、DB制約に加え、同時申請・同時承認による超過を防ぐDBトランザクションと行ロックを使用しています。
