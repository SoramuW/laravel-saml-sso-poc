# 実装・検証記録

検証日: 2026-10-07（Asia/Tokyo）

## 実行環境

- ホスト: macOS / Apple Silicon
- Docker Desktop: 4.37.2、Engine: 27.4.0、Compose: 2.31.0
- アプリ・DB・IdP: Linux arm64コンテナ
- ブラウザ: ホストChrome + Playwright、およびDocker内Chromium + Playwright 1.58.2
- PHP / Composer / Laravel生成・テストはDocker内で実行。ホストPHP / Composerは不使用。
- ホストChrome検証用Node.jsは一時ディレクトリの検証ツールとして使用。Dockerブラウザ検証も成功しており、利用者のホストNode.jsは不要。

| 項目 | 確認したバージョン |
| --- | --- |
| Laravel | 13.35.0 |
| PHP | 8.4.26 |
| Composer | 2.8.12 |
| MySQL | 8.4.11 |
| Keycloak | 26.8.0 |
| onelogin/php-saml | 4.3.2 |
| robrichards/xmlseclibs | 3.1.5 |

## 実行結果

| 確認内容 | 結果 |
| --- | --- |
| `docker compose up -d --build` | 成功 |
| `docker compose ps -a` | app healthy / db healthy / keycloak Up / init Exited (0) |
| Realm import | Keycloakログで`saml-demo imported`を確認 |
| テストユーザー | testuser / test@example.com / Test / Userをimport |
| `docker compose exec app php artisan migrate` | 初回は全migration成功、再実行はNothing to migrate |
| `docker compose exec app php artisan route:list` | login / acs / metadata / logout / slsを確認 |
| Laravelトップ画面 | 表示成功 |
| SP Metadata | HTTP 200、XML内のEntity ID / ACS / SLSを確認 |
| SAMLログイン | 署名付きAuthnRequest → KeycloakのID/Password画面 → 署名付きResponse → ACS → Laravelログイン成功 |
| NameID | `testuser`を取得・表示 |
| 属性 | username、email、firstName、lastNameの値を確認、全受信Attribute一覧を表示 |
| usersテーブル | SAML識別子・IdP Entity ID付きユーザーを作成してLaravel認証 |
| Single Logout | Laravel → Keycloak → SLS → Laravel未ログイン画面 |
| Keycloakセッション終了 | 同じブラウザCookieで再ログイン操作後、KeycloakのID/Password入力画面へ戻ることを確認 |
| `docker compose --profile test run --rm browser` | Metadata / ログイン / 属性 / 両側LogoutすべてPASS |
| PHPUnit | 28 tests / 39 assertions成功 |
| Pint | 36 PHPファイルの整形チェック成功 |
| Composer audit | 公表済み脆弱性の警告なし（検証時点） |
| `git diff --check` | 成功 |

ブラウザテストは画面遷移、パスワード入力、属性表示、Logout後の再認証画面を検証します。保存した成功画面は[ログイン後](images/logged-in.png)と[ログアウト後](images/logged-out.png)です。署名検証は有効なまま実行しています。

## テストした拒否条件

テストごとに一時的な署名鍵・証明書を生成し、正常な署名付きResponseを受理することを確認したうえで、以下を検証しています。秘密鍵をfixtureとしてコミットしていません。

- 未署名Response、改ざんされた署名、信頼していない証明書
- Issuer、Audience、Destination、ACS Recipientの不一致
- 空のAudience / Destination
- InResponseToの不一致、SPログイン要求のないACS
- NotBeforeが未来、NotOnOrAfterが過去、有効期限が現在から1時間を超えるAssertion
- 消費済みResponse / Assertionの再送
- 自動作成無効時の未登録ユーザー
- 既存メールへの暗黙の紐付け、別Issuerや別NameIDからのアカウント上書き
- ログアウト要求のないLogoutResponse、未署名・改ざん・InResponseTo欠落のLogoutResponse
- Attribute Mappingを変更してもユーザーを作成できること

ユーザー照合テストは分離されたSQLiteメモリDBを使い、実ブラウザの認証はComposeのMySQLを使用しています。

## 新規環境からの再検証

別の一時ディレクトリへソースをコピーし、vendor、証明書、APP_KEY、DBボリュームを持たない状態から、独立したComposeプロジェクト`laravel-saml-clean-check`で再構築しました。

```bash
docker compose -p laravel-saml-clean-check up -d --build
docker compose -p laravel-saml-clean-check --profile test run --rm browser
```

結果: 初期構築・Metadata・署名付きログイン・属性表示・両側Logoutがすべて成功。依存関係、SP鍵、IdP証明書、Realm、DB schemaを初回起動で準備できました。

既存プロジェクトとのポート競合を避けるため、メイン検証ではLaravelを18000、独立した新規環境ではLaravelを18002 / Keycloakを18081で公開しました。初回起動前に`.env.example`をコピーし、公開URLも同じポートへ変更しています。

## 実際のアクセス先

メイン検証環境は作業ディレクトリの`.env`に18000を設定して起動しています。

| 用途 | URL |
| --- | --- |
| Laravel | http://localhost:18000 |
| Login | http://localhost:18000/saml/login |
| ACS | http://localhost:18000/saml/acs |
| Metadata | http://localhost:18000/saml/metadata |
| Logout | http://localhost:18000/saml/logout |
| SLS | http://localhost:18000/saml/sls |
| Keycloak | http://localhost:8081/admin/ |

テストユーザー: `testuser` / `local-test-only`

管理ユーザー: `admin` / `local-admin-only`

## 作成・変更したファイル

Laravel 13の公式Composerテンプレートから標準構成を生成し、SAML実装を追加しました。

| 分類 | ファイル・ディレクトリ |
| --- | --- |
| Docker | `docker-compose.yml`、`docker/php/Dockerfile`、`docker/php/init.php`、`docker/php/entrypoint.sh` |
| Keycloak | `docker/keycloak/realm-export.json` |
| 設定 | `.env.example`、`config/saml.php`、`bootstrap/app.php` |
| SAML処理 | `app/Services/SamlService.php`、`app/Services/SamlUserResolver.php`、`app/Http/Controllers/SamlController.php` |
| ユーザー | `app/Models/User.php`、`database/migrations/2026_10_07_000001_add_saml_identity_to_users.php` |
| ルート・画面 | `routes/web.php`、`resources/views/welcome.blade.php` |
| 自動検証 | `tests/Feature/SamlValidationTest.php`、`tests/Feature/SamlUserResolverTest.php`、`tests/Support/SignedSamlResponse.php`、`scripts/browser-smoke.cjs` |
| 依存関係 | `composer.json`、`composer.lock` |
| Git・ビルド | `.gitignore`、`.gitattributes`、`.dockerignore`、`.editorconfig` |
| 記録 | `README.md`、`docs/VERIFICATION.md`、`docs/images/` |
| Laravel標準構成 | `artisan`、`app/Providers/`、`bootstrap/`、その他`config/`、標準`database/`、`public/`、`resources/css/`・`resources/js/`、`routes/console.php`、`storage/`のGitignore、`tests/TestCase.php`・標準テスト、`phpunit.xml`、`package.json`、`.npmrc`、`vite.config.js` |

環境ごとの`.env`、証明書、秘密鍵、vendor、DB、セッション、ログはコミット対象外です。

## 未実施・制限・移行時の課題

- **Windows + Docker Desktop実機での実行は未実施。** Dockerのみの構成、シェルスクリプトのLF固定、PowerShellの設定手順を用意しています。
- **IceWall実機との接続は未実施。** 接続先情報・信頼証明書・属性Mapping・NameID・署名設定の変更箇所はREADMEに記載しています。
- SSO/SLOはHTTP-Redirect、ACSはHTTP-POST。POST SLOは405で拒否します。Artifact Binding / 暗号化Assertionは対応範囲外です。
- SP開始のログインに限定しています。IdP開始ログイン、並行する複数タブのログインは受け入れ対象外です。
- IdP開始LogoutはToolkit経由のルートを用意していますが、実ブラウザで確認したのはSP開始Single Logoutです。
- 複数Audienceなど、本PoCの受信プロファイルと異なるIceWall応答は追加の適合検証が必要です。
- 開発用Keycloak・固定の公開テスト資格情報・artisan HTTPサーバーを使用しています。本番環境の配備は対象外です。
- 既存Realmは起動時importで更新されません。公開URL変更・SP鍵交換時はKeycloak Client側も更新が必要です。
- IdP鍵の自動ローテーションは行いません。IceWallや証明書更新時は信頼済み証明書を明示的に交換します。
- IdP移行では既存ユーザーのIssuer/NameIDを移行する手順も必要です。メール照合の有効化は接続IdPへの信頼を確認して判断します。
