# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール | 機能 |
|---|---|
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル / 模擬試験（分野別ヒートマップ・合格可能性スコア）/ 面談予約 / チャット / 学習時間・進捗・ストリーク管理 / 修了証の受領 |
| コーチ（coach） | 教材・演習問題・模試の管理 / 担当受講生の進捗フォロー / 面談対応・面談メモ / チャット |
| 管理者（admin） | ユーザー招待・管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / 面談回数の付与 / 全体ダッシュボード |

## 動作環境

- Docker Desktop / Docker Compose
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone <このリポジトリの URL>
cd <リポジトリ名>
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます（外部サービス連携のキーは後述）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

http://localhost:8000 にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |
|---|---|
| アプリケーション | http://localhost:8000 |
| phpMyAdmin（DB 確認） | http://localhost:8080 |
| Mailpit（メール確認） | http://localhost:8025 |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス | 備考 |
|---|---|---|
| 管理者 | admin@certify-lms.test | 全機能にアクセス可能 |
| コーチ | coach@certify-lms.test | IT 系資格の担当 |
| コーチ | coach2@certify-lms.test | ビジネス系資格の担当 |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## テスト

```bash
sail artisan test                  # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
sail artisan test --group=external-api  # 外部API関連のテストのみ実行
sail artisan test --exclude-group=external-api  # 外部API関連のテストを除外して実行
```

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## キューワーカーの起動
本アプリケーションでは、通知の非同期送信に Laravel のデータベースキューを使用しています。
`.env` で以下の設定を確認してください。
```dotenv
QUEUE_CONNECTION=database
```
開発環境で通知を非同期処理するには、別のターミナルで以下を実行してください。

```bash
sail artisan queue:work database
```
ワーカーは起動したままにしてください。停止する場合は `Ctrl+C` を押します。

### 失敗したジョブの確認と再試行
失敗したジョブを確認するには、以下を実行します。
```bash
sail artisan queue:failed
```
失敗原因を確認・修正した後、特定のジョブを再試行するには、以下を実行します。
```bash
sail artisan queue:retry <ID>
```
`<ID>` は `queue:failed` の結果に表示されたジョブIDに置き換えてください。
すべての失敗ジョブを再試行する場合は、以下を実行します。
```bash
sail artisan queue:retry all
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーするだけで、すべての機能がローカルで動作します（メールは Mailpit に配信されます）。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません

### Google Calendar 連携
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` — Google Calendar API の OAuth 認証に使用します。Google Cloud で OAuth クライアントを作成し、取得した値を設定してください。
- `GOOGLE_REDIRECT_URI` — Google OAuth 認証後のコールバック URL を設定します。ローカル開発環境では `http://localhost:8000/settings/google-calendar/callback` を使用します。Google Cloud 側の承認済みリダイレクト URI にも同じ URL を登録してください。

#### 1. Google Cloud の設定
Google Calendar 連携を利用するには、Google Cloud Console で Google Calendar API を有効化し、OAuth 認証情報を取得する必要があります。
1. [Google Cloud Console](https://console.cloud.google.com/) にアクセスし、プロジェクトを作成または選択します。
2. 「API とサービス」→「ライブラリ」から `Google Calendar API` を検索し、有効化します。
3. [Google Auth Platform](https://console.cloud.google.com/auth/overview) で OAuth 同意画面を設定します。
   - アプリ名やユーザーサポートメールを設定します。
   - 開発環境では、対象ユーザーを「External」に設定し、連携に使用する Google アカウントをテストユーザーに追加してください。
4. [Google Auth Platform のクライアント](https://console.cloud.google.com/auth/clients) から OAuth クライアントを作成します。
   - アプリケーションの種類：`ウェブ アプリケーション`
   - 承認済みのリダイレクト URI：前述の `GOOGLE_REDIRECT_URI` と同じ URL

作成後に表示されるクライアント ID とクライアント シークレットを取得・使用してください。

### Gemini 連携（AIチャット）

- `AI_CHAT_ENABLED` — AIチャット機能の有効・無効を設定します。true にするとAIチャットを利用できます。
- `GEMINI_API_KEY` — Gemini API の認証に使用します。Google AI Studio で API キーを取得して設定してください。
- `GEMINI_MODEL` — 使用するGeminiのモデルを設定します。デフォルトではgemini-3.8-flashを使用します。
- `AI_CHAT_HISTORY_LIMIT` — Gemini API に送信する会話履歴の最大件数を設定します。デフォルトでは直近20件を対象とします。
- `AI_CHAT_DAILY_LIMIT` — 受講生1人あたりの1日のメッセージ送信上限を設定します。デフォルトでは1日50件です。

Gemini連携を利用するには、Google AI Studio で Gemini API キーを取得し、`.env` に設定する必要があります。

#### 初期設定

1. [Google AI Studio](https://aistudio.google.com/) にアクセスし、Google アカウントでログインします。
2. API キーの管理画面から Gemini API キーを作成します。
3. 取得した API キーを `.env` の `GEMINI_API_KEY` に設定します。
4. 必要に応じて `.env` の その他の環境変数を変更します。
5. `.env` を変更した場合は、必要に応じて設定キャッシュをクリアします。

   ```bash
   ./vendor/bin/sail artisan config:clear
   ```

#### AIチャットの動作確認
1. Laravel アプリケーションを起動します。
2. 受講中の受講生アカウントでログインします。
3. AIチャットのウィジェット、または全画面のAIチャットを開きます。
4. メッセージを送信し、Geminiから回答が返ってくることを確認します。
5. 会話履歴が保存され、ページを移動した後も会話一覧から過去の会話を確認できることを確認します。

#### 注意事項
- AIチャットでは、利用している画面や学習状況に応じて、教材や受講登録の情報をGemini APIに送信します。
- Gemini API の利用には、Google AI Studio 側の利用制限や料金体系が適用されます。無料枠ではリクエスト数や利用量に制限があり、混雑状況などによってタイムアウトやエラーが発生する場合があります。
- Gemini API のモデルはアプリケーション側の設定に従います。Gemini 2.5 Flashの新規登録が終了している為、Gemini 3.8 Flash をデフォルトとして設定しています。
- Gemini API のエラーが発生した場合、AIチャットにエラーが表示されることがあります。時間をおいて再度送信してください。

### Stripe 連携（追加面談購入）
- `STRIPE_SECRET` — Stripe API の認証に使用します。Stripe ダッシュボードで開発者用のテスト用シークレットキー（`sk_test_...`）を取得して設定してください。
- `STRIPE_WEBHOOK_SECRET` — Stripe Webhook の署名検証に使用します。ローカル開発環境では Stripe CLI で `stripe listen` を実行し、表示された署名シークレット（`whsec_...`）を設定してください。

#### 初期設定
1. [Stripe ダッシュボード](https://dashboard.stripe.com/) にログインし、テスト環境を使用できる状態にします。
2. 開発者向けの API キー設定画面から、テスト用シークレットキー（`sk_test_...`）を取得し、`.env` の `STRIPE_SECRET` に設定します。
3. Stripe CLI をインストールし、ターミナルで Stripe アカウントにログインします。

   ```bash
   stripe login
   ```

4. Laravel アプリケーションがローカルで起動していることを確認し、別のターミナルで次のコマンドを実行します。

   ```bash
   stripe listen \
     --events checkout.session.completed,checkout.session.expired,checkout.session.async_payment_failed \
     --forward-to http://localhost:8000/webhooks/stripe
   ```

5. コマンドの実行結果に表示された Webhook 署名シークレット（`whsec_...`）を `.env` の `STRIPE_WEBHOOK_SECRET` に設定します。Stripe CLI が表示する値はローカル開発用です。本番環境などの別環境では、それぞれの Webhook 設定に対応する署名シークレットを使用してください。
6. `.env` を変更した場合は、必要に応じて設定キャッシュをクリアします。

#### 決済・Webhook の動作確認
1. Laravel アプリケーションと Stripe CLI の両方を起動しておきます。Webhook の転送中は `stripe listen` を実行しているターミナルを閉じないでください。
2. `student@certify-lms.test` などの受講中アカウントでログインし、追加面談パックの購入画面を開きます。
3. 購入するパックを選択し、Stripe Checkout 画面でテスト決済を行います。Stripe が案内するテストカード番号を使用し、有効期限・CVC などにはテスト用の値を入力してください。実際のカード情報は使用しないでください。
4. 決済完了後、購入履歴が「決済完了」になり、面談残数に購入回数が加算されていることを確認します。
5. Stripe CLI のターミナルで `checkout.session.completed` などのイベントが転送され、Laravel が正常に応答していることを確認します。
6. 必要に応じて、Checkout 画面でのキャンセルや期限切れ、決済失敗のケースも確認します。状態が変化するタイミングは Stripe のイベントによって異なります。

#### 注意事項

- Webhook は `/webhooks/stripe` で受信し、署名検証に成功したイベントだけを処理します。
- Stripe CLI の転送はローカル開発用です。デプロイ環境では、Stripe 側に Webhook エンドポイントを登録し、その環境専用の署名シークレットを設定してください。

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。
