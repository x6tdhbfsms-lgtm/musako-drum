# Oracle試験環境 総合確認（2026-10-16〜17）

対象はmusako-drumのみ。別プロジェクトstudio34は操作していない。
GitHub push、migration、DB初期化、既存試験データ削除、実メール送信、実決済なし。

## 判定と重要な発見

**本運用はまだ開始すべきではない。試験用の架空データだけで継続する。**
自動テストが全件成功しても、今回求められた講師間の分離を満たしていない。
重大な問題は原因調査・報告を優先する指示に従い、以下の権限を一括変更していない。

### High：担当外の請求・問い合わせ・手続きへの講師アクセス（1件、共通原因）

新規の試験講師Bで、講師A専用生徒の次のURLをブラウザから直接確認した。

| 対象 | 結果 |
|---|---|
| /staff/students/1 | 403：適切 |
| /staff/reservations/1 | 403：適切 |
| /staff/transfer-requests/1 | 403：適切 |
| /staff/invoices/1 | **閲覧可能：不適切**。金額・入金履歴が表示 |
| /staff/inquiries/1 | **閲覧可能：不適切**。更新フォームも表示 |
| /staff/procedure-requests | **担当外の契約申請を表示** |
| /staff/contract-change-requests/1 | **閲覧可能：不適切**。承認・却下ボタンも表示 |

逆方向も講師AからB専用生徒ID4・予約ID9は403、問い合わせID2は閲覧可能。
担当外の承認・更新ボタンは実行していない。サーバー側Policyのupdateも講師を
一律許可するため、表示だけの問題ではない。

原因：MonthlyInvoicePolicy、InquiryPolicy、ContractChangeRequestPolicy等が
teacher/adminを担当関係によらず許可する。請求一覧の生徒選択・月集計、
ProcedureRequestController、InquiryControllerの一覧も担当で絞っていない。
同様の実装がMembershipStatusRequestPolicy、PersonalInformationChangeRequestPolicy、
PaymentMethodChangeRequestPolicy、AdmissionApplicationPolicyにある（これらの全書込経路は未実操作）。

必要な修正：担当関係の定義を揃え、詳細Policyだけでなく一覧・件数集計・検索候補・
更新認可も同じ範囲へ制限する。管理者の全件アクセスは維持。
複数講師が同じ生徒を担当する場合や担当変更後の履歴の扱いも含むため、
生徒詳細だけの場当たり的な修正では不十分。双方向・URL直打ち・書込拒否の回帰テストが必要。

### Medium（2件）

1. 体験日の前でも「体験完了」にできる。10/18の試験体験を10/16に完了にできた。
   将来の実績を誤登録できる運用リスク。事前入会を許可する仕様と、受講実績の確定を
   分ける必要があるか確認が必要。今回、既存ルールを独断で変更していない。
2. バックアップは同一VM内のみ。外部保管と復元試験は未完了。
   日次成功だけではVM喪失時の復旧を保証できない。

### Low（2件）

1. 体験詳細・履歴・一部契約表示にadult/none/pending/regular等の英語値が残る。
2. 請求再発行画面の「再発行理由」ラベルが振替番号欄の説明と連結している。
   テキスト入力・再発行は成功するが、読み上げ・ラベル対応を改善する余地がある。

Critical：今回確認範囲で0。High：1（複数画面に共通）。Medium：2。Low：2。
未確認部分まで安全と保証する件数ではない。

## フレックス警告修正

FlexInactivityWarningサービスを追加し、スタッフ生徒詳細の判定を置換。

- 現在有効なフレックス契約から、supersedesの連続したフレックス履歴を遡る。
- 通常契約・期間の途切れで遡及を止める。回数・料金・時間・会場等の変更ではリセットしない。
- 承認・適用済み再開申請のeffective_onとprevious_enrollment_statusesから、
  対象契約の再開日を下限とする。未適用・未来の再開は用いない。
- approvedかつ実施済みのレッスン日から再計算。欠席、pending、未来予約、
  振替で置換済みの元予約、保留元予約は実施扱いにしない。
- completed_atまたは終了済みという既存回数計算の定義を基に、未来開始予約は明示的に除外。
- Asia/Tokyoの日付単位で2か月後（端数日は月末に丸める）から警告。
- 契約IDがない旧予約は、その日のコースと有効期間で一意に特定できる場合のみ照合。
  特定不能な履歴は推測して書き込まない。連結情報のない契約を勝手につなげない。
- DBカラム追加・既存履歴更新なし。Oracle既存予約の契約リンク欠損0、
  適用済み再開の対応契約情報欠損0を読取確認。
- 新規、1か月、前日深夜、2か月到達、実績、pending、未来、取消、通常切替、
  フレックス継続変更、再切替、再開、旧予約、欠席、HTTP画面を含む15テストを追加。
- Oracle上の新規フレックス生徒ID3で、開始直後の警告が消えたことを実ブラウザ確認。

## 依存ライブラリの修正

Composer監査でSymfony http-foundation 8.1.6のCVE-2026-108082を検出。
未認証HTTPリクエストでCPU負荷を増やすヘッダー解析の問題。
composer.jsonは変更せず、composer.lockの当該1パッケージだけ8.1.9へ更新。
公式情報：https://symfony.com/blog/cve-2026-108082-redos-in-headerutils-split-reachable-through-the-cache-control-request-header
更新後Composer auditは0件。Oracleにも反映済み。

## 実ブラウザ確認

- 公開体験：架空申込→受付番号発行→管理者一覧・詳細→承認→体験完了。
- 申込者専用管理リンクはブラウザ側ERR_BLOCKED_BY_CLIENTで開けず、迂回していない。
  入会申請から初回ログインまでのブラウザ一貫検証は未完了。
- 体験のcapacity共有、重複、却下、キャンセル、no_show、入会変換は全体HTTPテストに含む。
  これらを今回すべて実ブラウザで操作したという意味ではない。
- 請求：試験生徒Aの下書きを再生成し21,000円。
  電子45分4回17,000円＋会場3回3,000円＋直前振替1,000円。
  11/5振替の会場代は元の10月請求に入り、元予約の会場代と二重になっていない。
- A請求確定→試験入金1,000円→残額20,000円→試験入金20,000円→入金済み・残額0。
  20,001円はフォームで送信されず、残額・履歴不変。サーバー過入金拒否・
  同一送信再試行・競合はMySQLを含む自動テストで成功。
- B請求23,610円（通常22,000円＋会場1,610円）を確定→取消→再発行。
  元請求ID2・明細・監査履歴を保持し、再発行先ID4は下書きとして残す。
- 生徒Aの確定請求表示は自分の1件だけ。他生徒請求ID2直接指定は403。
- ログアウト後の請求アクセスは/loginへ転送。操作中HTTPS維持。
- 生徒請求詳細・契約手続き・予約詳細は390/320pxでdocument幅がviewportと一致。
  320px予約詳細スクリーンショットでカード・文字・ナビの重なりなし。
  前回のカレンダー・振替確認と合わせても全ページ・全端末の網羅検証ではない。
- ブラウザconsoleのerror/warnは今回取得時0。

## 全体テスト

負荷の大きいテストはMacの隔離Dockerだけで実行。Oracle業務DBでテストを実行していない。

| 項目 | 結果 |
|---|---|
| SQLite | 331 passed / MySQL専用4 skipped / 1,389 assertions |
| MySQL 8.4 全体 | 335 passed / 1,410 assertions |
| 新規フレックス | 15 passed / 18 assertions |
| Pint dirty test | passed |
| npm run build | passed |
| Blade cache | passed |
| Composer audit | 0件（更新後） |
| npm audit | 0件 |
| git diff --check | passed |

全体には予約・定員・体験・入会・振替・保留・月跨ぎ・営業時間・移動・定期・
料金・請求・入金・再発行・契約変更・休退会・通知・認可の既存テストを含む。
ただし既存テストは今回の担当外請求等の分離要件を保証していなかった。
今回npm ciは再実施していない（前回成功、今回フロント依存変更なし）。

## Oracle反映と監視

app/queue/schedulerをmicro-39acddb-ui2へ切替。
Macで既存x86イメージを基に、フレックス修正・保留ボタン修正・修正版依存を
重ねてビルドし転送。1GB VM上ではnpm/composerの全ビルドを行っていない。
webはmicro-39acddb-v1のまま（同内容ui2タグも付与し構成との互換を保持）。
Oracle作業ツリーへStudentController、FlexInactivityWarning、composer.lockを配置。
DB migration・業務マスタ変更なし。

app/web/mysql/queue/scheduler/backupの6サービスhealthy。
10/17確認：RAM596MiB/954MiB、Swap321MiB/2047MiB。
監視サンプル最大RAM606MiB、Swap321MiB（切替前の別読取はSwap363MiB）。
全サービスOOM=false、現行コンテナ再起動0。app/queue/schedulerは修正配備のため
意図して再作成した。旧Queueの定時終了回数と異常再起動を混同しない。
jobs=0、failed_jobs=0。Schedulerのbilling処理DONE。
直近日次backupは10/16 17:55成功。外部保存・復元試験は未実施。
HTTPS /up=200、HTTP /staff/dashboard=308で同じHTTPSパスへ転送。
Dockerの外部公開はwebの80/443のみ。MySQL/管理ポートの外部到達試験は前回結果を参照。

## 今回の試験データ（保持）

- 体験申込ID1：trial-admission-flow@example.invalid、10/18電子45分、completed。
- 講師B：trial-teacher-b@example.invalid、teacher_profile ID2。
- B専用生徒：trial-teacher-b-student@example.invalid、student_profile ID4、契約ID4。
  既存入会承認Actionで作成。30分フレックス・月2回、10/17開始。
- レッスン枠ID10：10/21 12:15〜12:45、講師B、電子会場、定員1。
  予約ID9は既存予約申請・承認Actionで作成。問い合わせID2は権限確認用。
- A請求ID1：confirmed/paid、試験入金2件（実入金なし）。
- B旧請求ID2：cancelled、再発行請求ID4：draft。既存C請求ID3も保持。
- 総数：users7、teachers2、students4、slots10、reservations9、invoices4。
- パスワードはGit外の所有者限定ファイルのみ。報告・Gitに記載しない。
  サーバー側の一時平文資格情報ファイルは取得後に除去。

## 未完了と本運用前の作業

1. 最優先は講師の担当外情報・操作の分離。確認できたHighを解消後、全回帰と
   双方向ブラウザ確認を再実施する。今は実在生徒を登録しない。
2. 体験→入会→初回ログインの一貫ブラウザ確認、体験の残り状態操作、
   全種類の契約変更とモバイル全画面は未完了。専用リンクのブラウザ遮断原因も確認する。
3. A1 6GB移行：ARMイメージを別構築し、バックアップ復元→移行先テスト→DNS切替。
   現VMのDBボリュームを上書きしない。PHP/MySQL/workerのメモリ枠を再測定する。
4. SMTP：arrayの隔離を保ったまま配信先制限付きで別試験。
   SPF/DKIM/DMARC、初期パスワード案内、通知重複、bounce、実配信前の宛先確認が必要。
5. 暗号化したVM外バックアップ・保持期間・復元手順・定期復元試験を整備。
6. 外形HTTPS、証明書期限、空き容量、OOM、Queue滞留・失敗、backup失敗の監視と通知先。
7. 正式ドメインはDNS/TLS/APP_URL/Cookie/メールリンクを試験し切替。
   DuckDNSの更新トークンをGitやログへ置かない。

大規模な権限変更は開始せず、ここまでの検証結果を保存する。

ログ補足：10/17のLaravel ERROR/WARNINGは取得時0件、カーネルOOM該当0件。
Caddyには配備中の12:34:27〜34 JSTにapp名前解決/接続失敗が計3件ある。
単一appを再作成した際の一時停止で、その後の操作と/upは復旧している。
エラーが一切ない／無停止配備できるという判定ではない。
