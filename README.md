# Laravel SAML SSO PoC

LaravelをSAML 2.0のService Provider（SP）として実装し、HPE IceWall Federationとの接続を検証するためのサンプルです。ローカルIdPにはKeycloakを使用します。Laravelの認証処理は共通で、IdP接続情報と属性名を設定から変更できます。

**Laravel / MySQL / Keycloakの起動、実ブラウザでのSAMLログイン・属性表示・Single Logout・Metadata取得を確認済みです。** IceWall実機との接続とWindows上での実行は未検証です。検証環境・結果・制限は[検証記録](docs/VERIFICATION.md)に記載しています。

## バージョンと構成

| ソフトウェア | 使用バージョン |
| --- | --- |
| Laravel | 13.35.0（`composer.lock`） |
| PHP | 8.4.26 |
| Composer | 2.8.12 |
| MySQL | 8.4.11 |
| Keycloak | 26.8.0 |
| SAMLライブラリ | `onelogin/php-saml` 4.3.2 |
| XML署名ライブラリ | `robrichards/xmlseclibs` 3.1.5 |

[Laravel 13](https://laravel.com/docs/13.x/releases)を採用し、依存関係は`composer.lock`で固定しています。[OneLogin PHP SAML Toolkit](https://github.com/SAML-Toolkits/php-saml/tree/4.3.2)はAuthnRequest、署名・Response検証、Metadata、Single Logoutを備え、Laravelや特定IdPに依存しないため採用しました。

```text
ブラウザ → Laravel（SP）⇄ SAML 2.0 ⇄ Keycloak（ローカルIdP）
               ↓                      IceWall（接続先を変更して検証）
             MySQL
```

| Composeサービス | 内容 |
| --- | --- |
| `init` | 初回SP鍵生成、公開証明書・SP URLを含むRealm importファイル作成。正常終了後に終了 |
| `app` | PHP / Laravel / Composer。開発用HTTPサーバー、依存導入、マイグレーション、初回ローカルIdP証明書取得 |
| `db` | MySQL。Laravelのユーザー・セッション・再送防止キャッシュを保存 |
| `keycloak` | `start-dev --import-realm`。検証専用H2 DBを専用ボリュームへ保存 |
| `browser`（`test` profile） | Playwrightによる任意のブラウザ検証 |

アプリのビルドにNode.jsは不要です。artisan / Composer / ブラウザ検証はDockerから実行できます。Webサーバーは検証用の`artisan serve`を使用し、Nginxは追加していません。

## 初回起動

必要なものはGitとDocker Desktop（WindowsではLinuxコンテナモード）です。ホストへPHP、Composer、MySQL、Keycloakを導入する必要はありません。

```bash
git clone https://github.com/SoramuW/laravel-saml-sso-poc.git
cd laravel-saml-sso-poc
docker compose up -d --build
docker compose ps
```

`.env`がなければ`init`が`.env.example`から作成します。初回はComposerの依存関係取得とKeycloak起動に時間がかかります。`app`がhealthyになったらブラウザからアクセスしてください。

設定を変更する場合は、起動前にコピーして編集します。

```powershell
# Windows PowerShell
Copy-Item .env.example .env
```

```bash
# Git Bash / macOS / Linux
cp .env.example .env
```

起動時に以下を実行します。

1. SP秘密鍵と自己署名公開証明書を`storage/saml/`に生成します（既存ファイルは保持）。
2. SP証明書・Entity ID・ACS/SLS URLをRealmテンプレートへ埋め込みます。
3. MySQLを起動し、KeycloakにRealm・Client・テストユーザーをimportします。
4. `composer install`、APP_KEY生成、`php artisan migrate --force`を実行します。
5. `local`環境かつ`keycloak`の場合に限り、Compose内部のIdP Metadataから署名証明書を初回取得して固定します。
6. LaravelのHTTPサーバーを起動します。

## URL・テストユーザー

| 用途 | 標準URL |
| --- | --- |
| Laravelトップ画面 | http://localhost:8000 |
| SAMLログイン | http://localhost:8000/saml/login |
| ACS | http://localhost:8000/saml/acs |
| SP Metadata | http://localhost:8000/saml/metadata |
| SAMLログアウト開始 | http://localhost:8000/saml/logout |
| Single Logout応答先 | http://localhost:8000/saml/sls |
| Keycloak管理画面 | http://localhost:8081/admin/ |

| ユーザー | ID | パスワード |
| --- | --- | --- |
| Keycloak管理者 | `admin` | `local-admin-only` |
| SAMLテストユーザー | `testuser` | `local-test-only` |

テストユーザーのemailは`test@example.com`、firstNameは`Test`、lastNameは`User`です。これらは公開されたローカル検証用資格情報です。

トップ画面から「SAMLログイン」を選び、Keycloakでテストユーザーを入力します。ログイン後はNameID、Laravelユーザー、マッピングされた属性、受信した全Attributeを表示します。「SAMLログアウト」はKeycloakを往復し、成功応答を検証してLaravelのセッションも終了します。

[実際のログイン後画面](docs/images/logged-in.png) / [ログアウト後画面](docs/images/logged-out.png)

### ポート競合時

初回起動前に`.env`で`APP_PORT`、`APP_URL`、`SAML_SP_ACS_URL`、`SAML_SP_SLS_URL`を同じ公開ポートに揃えてください。

```dotenv
APP_PORT=18000
APP_URL=http://localhost:18000
SAML_SP_ACS_URL=http://localhost:18000/saml/acs
SAML_SP_SLS_URL=http://localhost:18000/saml/sls
```

Keycloakのポートを変える場合は`KEYCLOAK_PORT`、`KEYCLOAK_PUBLIC_URL`、`SAML_IDP_ENTITY_ID`、`SAML_IDP_SSO_URL`、`SAML_IDP_SLO_URL`も揃えます。内部Metadata URLの`keycloak:8080`は変更しません。

**一度importしたRealmは起動時に再importされません。** 起動後にSP URLや証明書を変えた場合はKeycloak管理画面でClientを更新してください。`.env`だけ変えて再起動しても、既存Realmの設定は更新されません。

## Dockerの操作

```bash
# 起動 / 再構築
docker compose up -d --build

# 状態とログ
docker compose ps
docker compose logs --tail=100 app keycloak db

# Laravelコンテナでコマンドを実行
docker compose exec app php artisan migrate
docker compose exec app php artisan route:list
docker compose exec app composer install
docker compose exec app php artisan config:clear

# 停止（DB・Keycloakボリュームは保持）
docker compose down
```

LaravelからMySQLには`DB_HOST=db`で接続します。ブラウザ向けURLは`localhost`の公開ポートです。SAMLライブラリは`APP_URL`を公開URLの基準に使用し、コンテナ内部ポートをACS検証へ混在させません。

`.env`変更後は設定キャッシュを消してください。ComposeのポートやKeycloak設定も変えた場合は`docker compose up -d`でコンテナを再作成します。ComposeはMySQLのポートをホストへ公開していません。

## Keycloak Realm / Client

元の設定は[`docker/keycloak/realm-export.json`](docker/keycloak/realm-export.json)です。`init`が生成する実際のimportファイルは`realm_import`ボリュームに保存され、Git管理対象外です。

| 項目 | 設定 |
| --- | --- |
| Realm | `saml-demo` |
| Client ID / SP Entity ID | `laravel-saml` |
| Protocol | SAML |
| NameID | username、unspecified形式 |
| ACS | `.env`の`SAML_SP_ACS_URL`、HTTP-POST |
| Single Logout | `.env`の`SAML_SP_SLS_URL`、HTTP-Redirect |
| 署名 | Response・Assertionの両方を署名、RSA-SHA256 |
| SP署名検証 | 有効。生成したSP公開証明書をClientへ登録 |
| 属性Mapper | username / email / firstName / lastNameのUser Property Mapper |

## `.env`設定

全設定例は[`.env.example`](.env.example)、Laravelへの集約先は[`config/saml.php`](config/saml.php)です。Controller / Serviceから直接`env()`は呼びません。

| 変数 | 役割・標準値 |
| --- | --- |
| `APP_URL` / `APP_PORT` | ブラウザ向けLaravel URL / 公開ポート |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` | `db` / `3306` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `laravel_saml` / `laravel` / `laravel` |
| `DB_ROOT_PASSWORD` | MySQLのローカル開発用rootパスワード |
| `KEYCLOAK_PORT` / `KEYCLOAK_PUBLIC_URL` | `8081` / `http://localhost:8081` |
| `KEYCLOAK_ADMIN_USERNAME` / `KEYCLOAK_ADMIN_PASSWORD` | 管理者のローカル開発用資格情報 |
| `SAML_PROVIDER` | `keycloak`または`icewall`。認証ロジックは共通 |
| `SAML_SP_ENTITY_ID` | `laravel-saml` |
| `SAML_SP_ACS_URL` / `SAML_SP_SLS_URL` | SPの公開ACS / Single Logout URL |
| `SAML_SP_CERT_PATH` / `SAML_SP_KEY_PATH` | `storage/saml/sp.pem` / `storage/saml/sp.key` |
| `SAML_NAME_ID_FORMAT` | NameID形式のURI。標準はunspecified |
| `SAML_IDP_ENTITY_ID` | `http://localhost:8081/realms/saml-demo` |
| `SAML_IDP_SSO_URL` / `SAML_IDP_SLO_URL` | `http://localhost:8081/realms/saml-demo/protocol/saml` |
| `SAML_IDP_CERT_PATH` | `storage/saml/keycloak-idp.pem` |
| `SAML_LOCAL_METADATA_URL` | 初回ローカル証明書取得専用。固定のCompose内部URLのみ許可 |
| `SAML_AUTO_CREATE_USER` | `true`。未登録ユーザーの作成を許可 |
| `SAML_ALLOW_EMAIL_LINKING` | `false`。既存の未紐付けユーザーへのメール照合を明示的に許可する場合のみ`true` |
| `SAML_ATTRIBUTE_USERNAME` | `username` |
| `SAML_ATTRIBUTE_EMAIL` | `email` |
| `SAML_ATTRIBUTE_FIRST_NAME` / `SAML_ATTRIBUTE_LAST_NAME` | `firstName` / `lastName` |
| `SAML_AUTHN_REQUESTS_SIGNED` | `true`。AuthnRequestに署名 |
| `SAML_LOGOUT_REQUESTS_SIGNED` / `SAML_LOGOUT_RESPONSES_SIGNED` | `true`。送信Logoutメッセージに署名 |
| `SAML_WANT_ASSERTIONS_SIGNED` | `true`。Assertion署名も必須 |
| `SESSION_DRIVER` / `CACHE_STORE` | `database`。共有可能なセッション・再送防止キャッシュ |
| `SESSION_ENCRYPT` | `true`。保存セッションを暗号化 |
| `SESSION_SAME_SITE` / `SESSION_SECURE_COOKIE` | ローカルは`lax` / `false` |

証明書パスはコンテナ内のアプリルートからの相対パス、またはLinux絶対パスです。Windowsホストのパスを直接設定せず、マウント先のコンテナパスを使ってください。

## 認証・ユーザー照合・セキュリティ

```text
GET /saml/login → 署名付きAuthnRequest → Keycloakで認証
→ ブラウザからPOST /saml/acs → Response検証 → users照合/作成
→ Laravel認証セッション確立 → NameIDと全Attributeを表示
```

`SamlService`がstrictモードのToolkitを構成し、署名、信頼するIdP証明書、Issuer、Audience、Destination、ACS Recipient、NotBefore、NotOnOrAfter、InResponseToを検証します。Response署名は常に必須で、無効化する設定はありません。受信Destination / Audience / Conditionsの有効期限は必須とし、有効期限が現在から1時間を超える応答は拒否します。Toolkitの時刻差許容は180秒です。

ログイン要求は5分以内、SP開始のみを受け付けます。Response / Assertion IDを原子的なキャッシュ追加で24時間保持して再送を拒否し、認証後にセッションIDを再生成します。RelayStateをログイン後の任意リダイレクト先として使用しません。CSRF例外は`/saml/acs`と`/saml/sls`だけです。

ユーザーは`(saml_idp_entity_id, saml_name_id)`で照合します。新規ユーザーはname、email、NameID、IdP Entity IDを保持します。emailは有効な値が必要です。既存メールとの自動紐付けは標準では拒否し、明示的に許可しても他のSAML識別子に紐付いたアカウントへの上書きは行いません。`SAML_AUTO_CREATE_USER=false`なら未登録ユーザーを拒否します。

`/saml/logout`で署名付きLogoutRequestを送信し、`/saml/sls`で署名付きLogoutResponseと要求IDを検証してからLaravelセッションを破棄します。SLOはHTTP-Redirect Bindingです。GET/POSTルートは定義していますが、未対応のPOST Bindingは405で拒否します。

## 証明書とGit管理

- `storage/saml/sp.key`: 起動時生成のSP秘密鍵。Git管理禁止。
- `storage/saml/sp.pem`: SP公開証明書。MetadataとKeycloak Clientへ反映。
- `storage/saml/keycloak-idp.pem`: 初回ローカル起動で取得・固定するIdP公開証明書。
- `.env`、vendor、node_modules、ログ、セッション、DBデータ、鍵、証明書、ブラウザ検証の出力はGit管理対象外です。

公開証明書には秘密鍵は含まれませんが、このリポジトリでは環境ごとの証明書も一律除外します。ローカルIdP証明書の初回取得は開発用の信頼初期化です。既存証明書は自動更新せず、本番・IceWallでは管理者から取得した信頼済み証明書を配置してください。Keycloakボリュームを作り直すとIdP鍵も変わるため、証明書の明示的な交換が必要です。

## IceWallへの切り替え

1. IceWall側とSP Entity ID、ACS、SLS、NameID形式、属性名、署名要件を合意し、SP Metadataを登録します。
2. 信頼済みIceWall署名証明書を`storage/saml/icewall-idp.pem`等へ配置します。
3. `.env`の以下を変更します。

```dotenv
SAML_PROVIDER=icewall
SAML_IDP_ENTITY_ID=<IceWallのEntity ID>
SAML_IDP_SSO_URL=<IceWallのSSO URL>
SAML_IDP_SLO_URL=<IceWallのSLO URL>
SAML_IDP_CERT_PATH=storage/saml/icewall-idp.pem
SAML_NAME_ID_FORMAT=<合意したNameID形式URI>
SAML_ATTRIBUTE_USERNAME=<ユーザー名属性>
SAML_ATTRIBUTE_EMAIL=<メール属性>
SAML_ATTRIBUTE_FIRST_NAME=<名の属性>
SAML_ATTRIBUTE_LAST_NAME=<姓の属性>
SAML_AUTO_CREATE_USER=false
```

4. SPの公開URLが変わる場合は`APP_URL`、`SAML_SP_ENTITY_ID`、`SAML_SP_ACS_URL`、`SAML_SP_SLS_URL`も変更します。SP鍵・公開証明書は`SAML_SP_KEY_PATH` / `SAML_SP_CERT_PATH`で指定できます。
5. 送信署名・Assertion署名の設定を相手の仕様と整合させます。Response署名は必須のままです。
6. `docker compose exec app php artisan config:clear`を実行し、ログイン・属性・ユーザー照合・Single Logout・不正応答拒否を再検証します。

IceWall側に本実装のHTTP-Redirect SSO/SLO、HTTP-POST ACS、署名付きResponse、有効なemail、Conditionsの時間制約を満たす設定が必要です。POST SLO、Artifact Binding、暗号化Assertionの追加対応は含めていません。IdP移行では保存済みユーザーのIssuer/NameID紐付けも管理者が移行してください。

別ドメインのIdPからACSへのPOSTでセッションCookieを送る場合、本番HTTPSで`SESSION_SECURE_COOKIE=true`と`SESSION_SAME_SITE=none`を設定します。ローカルlocalhost同士では`lax`で確認しています。実運用では開発用Composeを流用せず、HTTPS、運用用Webサーバー、秘密情報管理、証明書更新を別途整備します。

## 自動テスト

```bash
# 署名・証明書・Issuer・Audience・Destination・ACS・有効期限・再送・ユーザー照合
docker compose exec app php artisan test

# 依存パッケージ監査 / コード整形チェック
docker compose exec app composer audit
docker compose exec app vendor/bin/pint --test

# Docker内の実ブラウザでログイン・属性・Single Logout・Metadataを確認
docker compose --profile test run --rm browser
```

ブラウザ検証は初回にPlaywrightイメージとnpmパッケージを取得します。スクリーンショットは`test-results/`へ保存します。標準のlocalhost公開URL向けで、Docker Desktopの`host.docker.internal`を使用します。

## トラブルシューティング

| 症状 | 確認箇所 |
| --- | --- |
| 起動しない | `docker compose ps -a`、`docker compose logs app init keycloak db`、Dockerの起動、ポート競合 |
| DB接続エラー | `DB_HOST=db`、DB資格情報、dbのhealthcheck。既存MySQLボリュームの資格情報は.env変更だけでは更新されない |
| Keycloakへの接続エラー | 公開ポート・SSO URL・Realm、内部URLをブラウザ向け設定に使っていないか |
| Signatureエラー | 信頼するIdP証明書、Keycloakの鍵更新、Clientへ登録したSP公開証明書 |
| Issuer / Audience / Destinationエラー | IdP Metadata、SP Entity ID、ACS、`APP_URL`と公開ポート。署名検証は無効化しない |
| 403: current SP login request | 5分の要求期限、CookieのSameSite/Secure、別ドメインACS POST、複数タブでのログイン |
| 403: SAML login rejected | `storage/logs/laravel.log`の理由、証明書、属性Mapping、有効なemail、ユーザー作成/紐付け設定 |
| ACSで419 | SAMLエンドポイント限定のCSRF例外が適用されているか |
| 時刻エラー | ホスト・コンテナ・IdPの時刻、NotBefore / NotOnOrAfter |
| 属性が空 | Keycloak Mapper、実際のAttribute名、`.env`のMapping |
| .env変更が反映されない | config cache、Compose再作成、既存Realmのimportスキップ |
| Logout失敗 | SLO URL、HTTP-Redirect Binding、Response署名、要求ID、SP証明書 |

## 主な実装ファイルとGit操作

認証は[`SamlService`](app/Services/SamlService.php)、ユーザー照合は[`SamlUserResolver`](app/Services/SamlUserResolver.php)、HTTP処理は[`SamlController`](app/Http/Controllers/SamlController.php)に分離しています。ルートは[`routes/web.php`](routes/web.php)、画面は[`welcome.blade.php`](resources/views/welcome.blade.php)、SAML識別子追加は[`migration`](database/migrations/2026_10_07_000001_add_saml_identity_to_users.php)です。

新規ディレクトリをGitリポジトリにする場合は`git init`し、`git remote add origin https://github.com/SoramuW/laravel-saml-sso-poc.git`を実行します。本実装は`feature/implement-saml-poc`ブランチで作業しています。

```bash
git status
git add .
git diff --cached --check
git diff --cached --name-only
# .envや鍵・証明書が含まれていないことを確認
git commit -m "Implement Laravel SAML SSO PoC"
```

作成・変更ファイルの分類と実際の検証結果は[検証記録](docs/VERIFICATION.md)を参照してください。
