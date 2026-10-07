# Laravel SAML SSO PoC

LaravelをSAML 2.0のService Provider（SP）として実装し、最終的に **HPE IceWall Federation** と接続するための検証プロジェクトです。ローカルではKeycloakをIdentity Provider（IdP）として利用します。

## 現在の状態

このREADMEは、初期構築前の設計・実装要件と受け入れ条件を記録したものです。Laravelアプリケーション、Docker構成、SAML連携はまだ実装していません。以下のURL、設定値、コマンドは構築時の予定であり、動作確認済みの手順ではありません。

## 目的とシステム構成

```text
ローカル検証: ブラウザ → Laravel（SP）⇄ SAML 2.0 ⇄ Keycloak（IdP）
移行後:       ブラウザ → Laravel（SP）⇄ SAML 2.0 ⇄ IceWall Federation（IdP）
                            ↓
                          MySQL
```

Keycloak固有の認証ロジックをControllerに持たせず、SAML標準の共通処理と設定で接続します。IceWallへの移行時はEntity ID、SSO/SLO URL、証明書、Attribute Mappingの変更を基本とし、接続先の仕様に応じて署名・Binding・NameID形式も確認します。実際のIceWallとの互換性は接続試験で確認します。

## 開発環境とDocker構成

Windows + Docker Desktop上で、Dockerのみで開発環境を構築します。ホストへのPHP、Composer、MySQL、Keycloakのインストールは不要とする設計です。

| サービス | 役割 | ブラウザからのアクセス（予定） |
| --- | --- | --- |
| `app` | Laravel / PHP、Composer、artisan | http://localhost:8000 |
| `db` | MySQL | 公開不要 |
| `keycloak` | ローカル検証用IdP | http://localhost:8081 |
| `nginx`（必要時） | Webサーバー | Laravelの公開ポートを担当 |

Node.jsが必要な場合もコンテナで実行します。コンテナ間通信はサービス名を使い、MySQLは`DB_HOST=db`、Keycloak内部アクセスは`http://keycloak:8080`とします。

ブラウザ向けSSO URLとコンテナ向けURLを区別します。IdPのEntity ID、署名内のIssuer、SPのACS/Destinationが一致するように設定し、内部ホスト名への単純な置換は行いません。

## リポジトリと予定するファイル構成

リポジトリ: https://github.com/SoramuW/laravel-saml-sso-poc

既存リポジトリから始める場合:

```bash
git clone https://github.com/SoramuW/laravel-saml-sso-poc.git
cd laravel-saml-sso-poc
```

新規ディレクトリから始める場合:

```bash
git init
git remote add origin https://github.com/SoramuW/laravel-saml-sso-poc.git
```

LaravelプロジェクトもDocker内のComposerで作成し、以下をGit管理します。

```text
app/                    # Controller、SAMLサービス、ユーザーモデル
config/saml.php         # SAML設定の集約
routes/                 # SAMLエンドポイント
resources/views/        # ログイン・受信属性の確認画面
database/               # usersテーブル等のマイグレーション
docker/                 # PHP等のDocker設定
docker/keycloak/        # Realm import用JSON
docker-compose.yml
.env.example            # 設定例（実際の秘密情報を含めない）
composer.json
composer.lock
artisan
README.md
```

`.gitignore`では少なくとも以下を除外します。証明書や秘密鍵はGitへ登録せず、別途配置します。Realmのexportに実環境の秘密情報を含めないことも確認します。

```gitignore
.env
vendor/
node_modules/
storage/logs/*
storage/framework/*
storage/saml/*
docker/mysql/data/
*.key
*.pem
```

初期実装後は、機密情報が含まれていないことを確認してコミットします。

```bash
git status
git diff --cached
git add .
git diff --cached
git commit -m "Initial Laravel SAML environment"
```

## Docker環境の構築・起動・停止（実装後の予定）

`.env.example`をコピーして`.env`を作成します。Windows PowerShellでは`Copy-Item .env.example .env`、Git Bash等では`cp .env.example .env`を使用します。

```bash
docker compose up -d --build
docker compose ps
```

初回の依存関係導入、APP_KEY生成、マイグレーション、Realm import、IdP証明書配置の実行順と自動化範囲は、Docker構成の実装時に確定します。原則として`docker compose up -d --build`で各サービスを起動できるようにします。

Laravelへのアクセスは http://localhost:8000 を予定しています。コンテナ内でのコマンド例:

```bash
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan config:clear
```

停止:

```bash
docker compose down
```

## Keycloakの設定

管理画面: http://localhost:8081

| 項目 | 予定値・要件 |
| --- | --- |
| Realm | `saml-demo` |
| SAML Client / SP Entity ID | `laravel-saml` |
| ACS URL | `http://localhost:8000/saml/acs` |
| SP Logout応答先 | `http://localhost:8000/saml/sls`（実装時に確定） |
| 属性 | `username`、`email`、`firstName`、`lastName` |
| NameID | Laravelユーザーを識別できる値・形式を設定 |
| 署名 | SAMLResponse署名を有効化。Assertion署名等はSP側設定と整合させる |

Realm/Client設定は可能な限り`docker/keycloak/`のJSONから起動時にimportし、手動設定を減らします。管理ユーザーとテストユーザーの資格情報は実装時に定め、ローカル開発専用として明記します。

**テストユーザーは未作成です。** ログインID、パスワード、メールアドレスは構築後にここへ記載します。実環境の資格情報は記載しません。

## Laravel側の実装方針

- SAMLライブラリを利用し、AuthnRequest生成、Response検証、Metadata出力、Logout処理を共通サービスへ集約します。使用ライブラリとバージョンは未選定です。
- `.env`の値は`config/saml.php`に集約し、アプリケーションコードでは`config('saml.xxx')`経由で参照します。Controller等で直接`env()`を呼びません。
- `SAML_PROVIDER=keycloak` / `icewall`で接続先を指定できるようにします。Provider固有の処理が必要な場合のみサービスへ分離します。
- 認証成功時にNameID、email、username、その他Attributeをセッションへ保存し、Laravelの認証セッションを確立します。
- `users`テーブルのユーザーをメールアドレスまたはNameIDで検索します。照合の優先順位と識別子の保存方法を定め、意図しないアカウント紐付けを防ぎます。
- 未登録ユーザーは検証用に自動作成できるようにし、`SAML_AUTO_CREATE_USER=false`で無効化可能にします。

トップ画面は未ログイン時にSAMLログインボタンと現在のProviderを表示します。ログイン後はユーザー情報、NameID、メールアドレス、ユーザー名、受信Attribute一覧、ログアウトボタンを表示します。Attribute一覧はIceWall接続時のデバッグにも使用します。

## SAMLエンドポイント（予定）

| メソッド | パス | 役割 |
| --- | --- | --- |
| GET | `/saml/login` | AuthnRequestを生成しIdPへリダイレクト |
| POST | `/saml/acs` | SAMLResponseを受信・検証しLaravelへログイン |
| GET | `/saml/metadata` | SP Metadataを公開 |
| GET | `/saml/logout` | SPからSAML Logoutを開始 |
| GET / POST | `/saml/sls` | IdPからのLogout要求・応答を処理（Bindingに合わせて確定） |

Metadata URL: http://localhost:8000/saml/metadata  
ACS URL: http://localhost:8000/saml/acs  
Login URL: http://localhost:8000/saml/login  
Logout URL: http://localhost:8000/saml/logout

ACSへの外部POSTを受け付けるためのCSRF例外は必要なSAMLエンドポイントに限定します。SAMLの検証は常に実施します。

## `.env`のSAML設定（予定）

```dotenv
SAML_PROVIDER=keycloak

SAML_SP_ENTITY_ID=laravel-saml
SAML_SP_ACS_URL=http://localhost:8000/saml/acs
SAML_SP_SLS_URL=http://localhost:8000/saml/sls

SAML_IDP_ENTITY_ID=http://localhost:8081/realms/saml-demo
SAML_IDP_SSO_URL=http://localhost:8081/realms/saml-demo/protocol/saml
SAML_IDP_SLO_URL=http://localhost:8081/realms/saml-demo/protocol/saml
SAML_IDP_CERT_PATH=storage/saml/keycloak-idp.pem

SAML_AUTO_CREATE_USER=true
```

IdP側の値は実際のMetadataと照合して設定します。`SAML_IDP_CERT_PATH`はアプリケーションのルートを基準に解決する設計とします。

Attribute Mappingも設定で変更可能にします。環境変数名は実装時に確定し、`.env.example`と本節に反映します。

| Laravel側の情報 | Keycloakからの属性（予定） |
| --- | --- |
| 外部識別子 | SAML NameID（通常のAttributeとは別） |
| ユーザー名 | `username` |
| メールアドレス | `email` |
| 名 | `firstName` |
| 姓 | `lastName` |
| デバッグ表示 | 受信したすべてのAttribute |

## 署名・証明書・セキュリティ要件

次を検証し、署名検証を無効にして動かす構成にはしません。

- SAMLResponse署名と、信頼するIdP証明書との整合性
- Issuer、Audience、Destination、ACS URL
- NotBefore、NotOnOrAfter
- SPが開始したログインの要求・応答の対応、および再送応答への対策

IdP公開証明書は`storage/saml/`等へ配置し、IceWallへの切り替え時に交換します。SP署名が必要な場合はSP証明書・秘密鍵の設定も追加します。ローカルと本番の設定は分離し、本番のHTTPS、Cookie設定、証明書更新、ユーザー自動作成の可否を接続時に確認します。

## SAML認証フロー

1. ブラウザでLaravelトップ画面を開き、「SAMLログイン」を選択します。
2. `/saml/login`でAuthnRequestを生成し、Keycloakへリダイレクトします。
3. KeycloakでIDとパスワードを入力します。
4. Keycloakがブラウザ経由で`/saml/acs`へSAMLResponseをPOSTします。
5. Laravelが署名・各条件を検証し、NameIDとAttributeを取得します。
6. Laravelユーザーを検索または自動作成し、セッションを確立します。
7. トップ画面でユーザー情報と受信Attributeを確認します。

ログアウトはLaravel → SAML Logout → Keycloak → Laravelの往復を実装し、LaravelとIdPの両セッションが終了することを確認します。Laravel内のログアウトだけではSAML Single Logoutの確認完了としません。

## KeycloakからIceWallへの切り替え

1. IceWall管理側とSP Entity ID、ACS URL、Logout URL、Binding、NameID形式、署名要件を合意し、Laravel SP Metadataを登録します。
2. IceWallのIdP Metadataまたは接続情報と署名用公開証明書を取得します。
3. `SAML_PROVIDER=icewall`へ変更します。
4. `SAML_IDP_ENTITY_ID`、`SAML_IDP_SSO_URL`、`SAML_IDP_SLO_URL`を変更します。
5. IceWall証明書を配置し、`SAML_IDP_CERT_PATH`を変更します。
6. IceWallの属性名に合わせてAttribute Mappingを変更します。
7. 本番SPのURLが異なる場合は`SAML_SP_ENTITY_ID`、`SAML_SP_ACS_URL`、`SAML_SP_SLS_URL`も変更します。
8. 設定キャッシュを更新し、ログイン・属性・ユーザー照合・Logout・不正応答の拒否を再検証します。

基本の認証ロジックは共通のままとしますが、設定変更のみでの接続可否はIceWall実機との検証で確定します。

## 動作確認と完了条件

現時点では以下はすべて未確認です。実装後に実行結果を記録します。

- [ ] `docker compose up -d --build`でLaravel / MySQL / Keycloakが起動する
- [ ] Windows + Docker DesktopでホストPHP / Composerなしに構築できる
- [ ] Realm / Client設定をimportでき、IdP証明書をLaravelへ配置できる
- [ ] SP Metadataを取得し、Entity ID / ACS / Logout設定を確認できる
- [ ] SAMLログイン → Keycloak認証 → ACS → Laravelログインが成功する
- [ ] NameID、username、email、firstName、lastNameと受信属性一覧が表示される
- [ ] 既存ユーザーの照合、新規ユーザー作成、自動作成無効時の拒否が確認できる
- [ ] 不正署名、Issuer / Audience / Destination不一致、期限切れ等を拒否する
- [ ] SAML Logoutの往復でLaravelとKeycloakのセッションが終了する
- [ ] 秘密情報、`.env`、鍵、証明書、実行データがGit管理に含まれない
- [ ] 使用ライブラリ、バージョン、起動手順、テストユーザー、検証結果をREADMEへ反映する

## トラブルシューティング（実装後の確認観点）

| 症状 | 確認する項目 |
| --- | --- |
| コンテナが起動しない | `docker compose ps`、`docker compose logs app db keycloak`、ポート競合、環境変数 |
| MySQLに接続できない | `DB_HOST=db`、DB名・資格情報、DB起動完了 |
| Keycloak画面へ遷移できない | ブラウザ向けSSO URL、公開ポート、Realm名 |
| Issuer / Audience / Destinationエラー | IdP Metadata、SP Entity ID、ACS URL、外部URLと内部URLの混同 |
| 署名エラー | IdP証明書、鍵更新、Response / Assertionの署名設定 |
| 期限エラー | コンテナ・ホスト・IdPの時計、許容時刻差、Assertionの有効期間 |
| ACSで419になる | ACSに限定したCSRF例外と、セッションCookieのSameSite等の設定 |
| 属性が取得できない | Keycloak Mapper、属性名・形式、Laravel Attribute Mapping |
| `.env`変更が反映されない | `docker compose exec app php artisan config:clear` |
| ログアウト後に再認証なしで戻れる | IdPセッションの終了、SLO URL、Binding、Logout要求・応答の検証 |

署名検証の無効化をトラブル対応策にはしません。実装完了時には、Gitの状態・初回コミット・作成ファイル・使用SAMLライブラリ・Docker構成・Keycloak/Laravel設定・変更した環境変数・実際の検証結果を記録します。
