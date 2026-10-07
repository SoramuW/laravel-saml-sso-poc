# LaravelへのSAML認証実装：手順と進め方

この文書は、このPoCを動かして仕組みを理解し、別のLaravelシステムへSAML認証を組み込むための実装ガイドです。実装手順書や技術記事の下書きとしても利用できます。

現在のPoCではKeycloakとのログイン・属性表示・Single Logoutまで確認済みです。以下の段階は、別のシステムで同じ機能を作るときの推奨作業順です。IceWall実機とWindows実機での確認はこれから行う作業として扱います。

- 起動コマンド・設定一覧：[README](../README.md)
- 実際の検証結果と対応範囲：[VERIFICATION](VERIFICATION.md)
- 別システム導入時の記入用シート：[INTEGRATION_CHECKLIST](INTEGRATION_CHECKLIST.md)

## 1. まず決めること

このPoCではLaravelがSP、KeycloakまたはIceWallがIdPです。IdPが認証した結果をLaravelが検証し、検証に成功したユーザーをLaravelのログイン状態にします。

| 用語 | この実装での役割 |
| --- | --- |
| SP Entity ID | Laravel側を識別する値。ログイン画面のURLとは別に決める |
| IdP Entity ID / Issuer | 信頼する認証元の識別子。IdP Metadataの値と一致させる |
| SSO URL | AuthnRequestの送信先 |
| ACS URL | ブラウザからSAMLResponseがPOSTされるLaravelのURL |
| SLO URL | IdPへLogoutRequestを送るURL |
| SP SLS URL | IdPからのLogout要求・応答を受けるLaravelのURL |
| NameID | IdPから渡されるユーザー識別子。形式と安定性を確認する |
| Attribute | emailやusernameなどの付加情報。名前・複数値の有無はIdPと合意する |
| IdP公開証明書 | 受信メッセージの署名を検証するために、あらかじめ信頼する証明書 |
| SP鍵・公開証明書 | Laravelから送る要求への署名と、IdP側での検証に使用する |

最初に「既存の誰をログインさせるか」を決めてください。SAML認証成功と、アプリケーションを利用する権限の付与は別の判断です。このPoCには業務ロールや所属に基づくアクセス制御は追加していません。

**完了条件：** SP/IdPの担当、公開URL、ユーザー識別方法、属性、Binding、署名条件をチェックシートへ記入できること。

**次にやること：** まずこのPoCの標準構成を起動し、環境の問題とSAMLの問題を切り分けます。

## 2. 全体の進行順

| 段階 | 作業 | 次へ進める条件 | 失敗したときの確認先 |
| --- | --- | --- | --- |
| A | DockerとDBを起動 | app/dbがhealthy、Laravelトップが表示される | Composeログ、ポート、DB設定 |
| B | IdPと信頼情報を準備 | Realm/Client、証明書、SP Metadataが揃う | Realm import、証明書パス、Metadata |
| C | IdPへのリダイレクト | Keycloakの資格情報入力画面が開く | SSO URL、SP署名、Client ID、ACS |
| D | ACSで認証結果を検証 | 署名等の検証後にNameID/属性が取得できる | Laravelログ、Issuer/Audience/時刻 |
| E | Laravelユーザーへ紐付け | ログイン状態になり、同じユーザーを再利用できる | Resolver、users、属性Mapping |
| F | 拒否条件を検証 | 不正署名・期限切れ・再送等を拒否できる | 署名付きfixtureと自動テスト |
| G | Single Logout | LaravelとIdPの両側でセッションが終了する | SLO/SLS、署名、要求ID、SessionIndex |
| H | 別システム/別IdPへ導入 | 導入先のユーザー・権限・URLで同じ試験に通る | 導入チェックシート、相手のMetadata |

一度にIdP、URL、属性、ユーザー照合をすべて変更すると、失敗した原因を特定しにくくなります。段階ごとに動作と設定値を記録し、変更した項目を追えるように進めます。

## 3. 段階A：Docker・Laravel・DBを起動する

### やること

```bash
git clone https://github.com/SoramuW/laravel-saml-sso-poc.git
cd laravel-saml-sso-poc
```

初回起動前に`.env.example`を`.env`へコピーします。PowerShellでは`Copy-Item .env.example .env`、Git Bash/macOS/Linuxでは`cp .env.example .env`です。

標準URLはLaravelが`http://localhost:8000`、Keycloakが`http://localhost:18001`です。8000が使用済みなら、以下をまとめて変更します。この作業環境では18000を使っています。

```dotenv
APP_PORT=18000
APP_URL=http://localhost:18000
SAML_SP_ACS_URL=http://localhost:18000/saml/acs
SAML_SP_SLS_URL=http://localhost:18000/saml/sls
```

```bash
docker compose up -d --build
docker compose ps -a
docker compose exec app php artisan migrate
docker compose exec app php artisan route:list
```

`init`のExited (0)は正常です。初回依存導入、SP鍵生成、Realm import、マイグレーション、開発用IdP証明書取得は起動処理に含まれます。

### 試すこと

1. Laravelトップ画面を開き、Providerとログインボタンが表示されるか確認します。
2. Keycloak管理画面`http://localhost:18001/admin/`を開きます。
3. LaravelログとComposeログに起動失敗がないか確認します。

```bash
docker compose logs --tail=100 init app db keycloak
```

**完了条件：** Laravelトップ表示、DB接続、ルート登録、Keycloak起動が確認できること。

**次にやること：** ログインボタンを押す前に、両者が同じEntity ID・URL・証明書を参照しているか確認します。

### 新規Laravelを自分で構築する場合のヒント

このリポジトリにはLaravel本体があるため、clone後に`create-project`を再実行する必要はありません。ゼロから手順書を作る場合は、まず[PHP/ComposerのDockerfile](../docker/php/Dockerfile)を用意し、Docker内で新規Laravelを生成する段階を追加します。

```bash
# 新規作業ディレクトリで、上記Dockerfileをdocker/php/へ配置してから実行
docker build -t laravel-saml-php:dev -f docker/php/Dockerfile .
docker run --rm -v "${PWD}:/work" -w /work laravel-saml-php:dev composer create-project laravel/laravel laravel-app "^13.0" --no-scripts
```

これは`laravel-app/`に標準構成を作る例です。本PoCと同じルート構成にする場合は、生成した標準ディレクトリ、artisan、composer.json/lock等を作業ルートへ配置し、DockerのWORKDIR/マウント先をそのルートに合わせます。作成済みのREADMEやDocker設定をテンプレートで上書きしないようにします。

採用バージョンを記録し、導入先のPHP/Laravelに合わせてライブラリ互換性を確認してください。このPoCのバージョンはREADMEに記録しています。

## 4. 段階B：IdP設定・証明書・Metadataを揃える

### 読むファイル

- [Realmテンプレート](../docker/keycloak/realm-export.json)：Client、テストユーザー、Mapper
- [init.php](../docker/php/init.php)：SP公開証明書とURLをimport用ファイルに埋め込む処理
- [config/saml.php](../config/saml.php)：環境変数をLaravelの設定へ集約
- [SamlService::settings()/metadata()](../app/Services/SamlService.php)：Toolkit設定とSP Metadata
- [BootstrapLocalSaml](../app/Console/Commands/BootstrapLocalSaml.php)：ローカル限定の初回証明書固定

### 試すこと

1. 管理画面でRealm `saml-demo`、SAML Client `laravel-saml`を確認します。
2. ACSとSLSがLaravelの公開URLを指しているか確認します。
3. KeycloakのResponse/Assertion署名と、SP要求の署名検証が有効か確認します。
4. Laravelの`/saml/metadata`を開き、Entity ID、ACS、SLS、SP公開証明書を確認します。
5. `storage/saml/sp.key`、`sp.pem`、`keycloak-idp.pem`が生成・配置されているか確認します。秘密鍵の内容を記事やログへ掲載しません。

コンテナ向けの`http://keycloak:8080`と、ブラウザ向けの`http://localhost:18001`を区別してください。Entity IDはMetadataに記載された識別子であり、内部通信URLへ機械的に置き換える値ではありません。

**完了条件：** SP MetadataとIdP設定が整合し、Laravelが信頼するIdP証明書を読めること。

**次にやること：** AuthnRequestを送ってIdPのログイン画面まで進めます。

## 5. 段階C：SAMLログイン要求を送る

### 実装の順番

1. [routes/web.php](../routes/web.php)で`GET /saml/login`を登録します。
2. [SamlController::login()](../app/Http/Controllers/SamlController.php)から共通のSamlServiceを呼びます。
3. Toolkitに署名付きAuthnRequestを生成させます。
4. 要求IDと作成時刻をLaravelセッションへ保存します。
5. 設定されたSSO URLへリダイレクトします。

この時点ではLaravelユーザーを作成しません。認証結果を受け取り、検証してから紐付けます。

### 試すこと

トップ画面の「SAMLログイン」を押します。ブラウザの開発者ツールでNetworkのログを保持し、Laravel → Keycloakの順に遷移することを確認します。

Keycloakの画面が開かない場合は、SSO URL、公開ポート、Client ID、SP署名用証明書、ACS設定を調べます。まだACSやユーザー作成のコードを変更する段階ではありません。

**完了条件：** テストユーザーのID/Password入力画面が表示されること。

**次にやること：** テストユーザーで認証し、ACSへPOSTが届くことを確認します。

## 6. 段階D：ACSでSAMLResponseを検証する

### 実装の順番

1. `POST /saml/acs`を登録します。
2. [bootstrap/app.php](../bootstrap/app.php)で必要なSAMLエンドポイントだけをCSRF例外にします。
3. セッションに保存したログイン要求IDと、その要求が5分以内かを確認します。
4. [SamlService::validateResponse()](../app/Services/SamlService.php)で署名、IdP証明書、Issuer、Audience、Destination、Recipient、時間条件、InResponseToを検証します。
5. 検証済みのResponse/Assertion IDを共有キャッシュへ記録し、再送を拒否します。
6. この後にNameIDとAttributeをユーザー照合へ渡します。

署名や条件を検証する前に、受信XMLの値を使ってLaravelへログインさせないことが実装の境界です。XML署名を自作せず、Toolkitの検証を使用します。

### 試すこと

テストユーザーは`testuser` / `local-test-only`です。Keycloak認証後、ブラウザから`/saml/acs`へPOSTされることを確認します。

403の場合は`storage/logs/laravel.log`の拒否理由を確認します。要求がない場合はCookieや要求期限、検証エラーの場合は証明書・識別子・URL・時刻を確認します。署名検証を無効化して先へ進みません。

**完了条件：** 正常な署名付き応答だけが受理され、NameIDと属性を取得できること。

**次にやること：** 受信した外部ユーザーを、アプリケーションのユーザーへ安全に対応付けます。

## 7. 段階E：Laravelユーザーと紐付ける

### 読むファイル

- [SamlUserResolver](../app/Services/SamlUserResolver.php)：照合、明示的なメール紐付け、自動作成
- [User](../app/Models/User.php)と[SAML識別子migration](../database/migrations/2026_10_07_000001_add_saml_identity_to_users.php)
- [SamlController::acs()](../app/Http/Controllers/SamlController.php)：Auth::loginとセッション更新
- [welcome.blade.php](../resources/views/welcome.blade.php)：属性確認画面

このPoCは`(IdP Entity ID, NameID)`を組み合わせて照合します。同じNameIDでも認証元が違えば同一人物とは扱いません。有効なemailを必須とし、属性値はマッピングされたAttributeの先頭値を使います。

`config/auth.php`はLaravelのguard/providerを定義する場所です。本PoCでは標準のwebセッションguardとUserモデルを使っています。SAML接続先は`config/saml.php`、照合方針はResolverへ置いています。導入先が別guardや別Userモデルなら、その接続部分を合わせます。

### 試すこと

| 試験 | 期待する結果 |
| --- | --- |
| 初めてのNameIDでログイン | 自動作成有効ならユーザーが1件作成される |
| Logoutして同じユーザーで再ログイン | 同じLaravelユーザーを使い、重複作成しない |
| 未登録ユーザーで自動作成無効 | ログインを拒否する |
| 既存ユーザーと同じemail、外部IDは未登録 | 標準設定では紐付けを拒否する |
| すでに他のNameIDへ紐付いたemail | 外部IDを上書きしない |
| 属性Mappingを変更 | 受信属性名を変えても照合できる |

別システムでは「従業員番号で紐付ける」「管理者が事前登録する」などの方針を、この段階で決めます。自動作成だけを無効にしても利用者の権限設計まで完成するわけではありません。

**完了条件：** 正しい内部ユーザーへログインし、繰り返しのログインと拒否条件を確認できること。

**次にやること：** 正常系に加え、不正な認証結果を拒否する試験を実施します。

## 8. 段階F：セキュリティ条件を試す

```bash
docker compose exec app php artisan test
docker compose exec app composer audit
docker compose exec app vendor/bin/pint --test
```

[署名検証テスト](../tests/Feature/SamlValidationTest.php)と[ユーザー照合テスト](../tests/Feature/SamlUserResolverTest.php)を読み、どの条件が拒否されるか確認してください。署名付きテスト応答は[SignedSamlResponse](../tests/Support/SignedSamlResponse.php)で生成します。

特にIssuerやAudienceの異常系は「誤った値を入れたうえで正しく署名したXML」で試します。署名後に文字列だけを書き換えると署名改ざんとして拒否され、Issuer等の検証が機能しているかを独立して確認できません。

本PoCの検証範囲は、未署名・改ざん・不正証明書、Issuer/Audience/Destination/ACS不一致、期限、要求ID、再送、ユーザー紐付け、LogoutResponseの署名・対応関係です。現在の正常系fixtureが受理されることも合わせて確認します。

**完了条件：** 正常な応答を受理し、対象の不正応答を拒否するテストが通ること。

**次にやること：** LaravelだけでなくIdP側のセッションも終了するLogoutを実装・検証します。

## 9. 段階G：Single Logoutを完了させる

1. 認証時のNameID、NameID形式、SessionIndexを保存します。
2. `GET /saml/logout`から署名付きLogoutRequestを送り、要求IDをセッションへ保存します。
3. `/saml/sls`でLogoutResponseの署名・Issuer・Destination・InResponseTo・成功ステータスを確認します。
4. 成功確認後にLaravelの認証状態を解除し、セッションを破棄します。

この処理は[SamlController::logout()/sls()](../app/Http/Controllers/SamlController.php)と[SamlService::validateLogoutProfile()](../app/Services/SamlService.php)で追えます。

### 試すこと

1. SAMLログイン後に「SAMLログアウト」を選びます。
2. IdPを経由してLaravelの未ログイン画面へ戻ることを確認します。
3. 同じブラウザ・同じCookieのまま再び「SAMLログイン」を選びます。
4. KeycloakのID/Password画面が表示されることを確認します。

別のシークレットウィンドウでは、元のIdPセッションが終了したことの証拠になりません。Laravelだけのログアウトと区別するため、同じブラウザ状態で確認します。

```bash
# Metadata・ログイン・全属性・両側Logoutをまとめて検証
docker compose --profile test run --rm browser
```

**完了条件：** LaravelとIdPの両セッション終了を確認できること。

**次にやること：** 導入先の認証・権限・運用条件へ合わせて移植します。

## 10. 段階H：他のシステムへ移植する

### そのまま参考にできる部分と、調整する部分

| 対象 | 移植時にすること |
| --- | --- |
| SamlService | Toolkitの共通設定・署名検証の構成を参考にし、受信プロファイルを相手に合わせる |
| config/saml.php | 導入先の設定管理へ組み込み、URLや証明書をControllerへ直書きしない |
| SamlController | セッションguard、ルート名、戻り先、エラー表示を導入先に合わせる |
| SamlUserResolver | 既存ユーザースキーマ、識別子、事前登録、無効ユーザー・退職者、所属・権限の規則を実装する |
| migration / User | 既存DBへ必要な外部IDを追加。導入先の既存データと制約を確認する |
| Attribute画面 | 検証環境では調査に使い、実運用の公開範囲は属性に含まれる情報に合わせて決める |
| Docker/Keycloak import | ローカル検証環境として使う。導入先の運用基盤へ合わせる |
| テスト | 導入先の正常応答、属性名、識別子、権限規則で正常系と拒否条件を再実行する |

本PoCは単一の設定されたIdP、SP開始ログイン、HTTP-Redirect SSO/SLO、HTTP-POST ACS、平文Assertionの署名検証を対象にしています。複数IdP、API認証、IdP開始ログイン、POST SLO、Artifact、暗号化Assertionが必要なら、対応方針を先に決めて追加実装します。

### IceWallなど別IdPへ切り替える順番

1. 相手のMetadata・署名証明書・属性仕様を受け取り、Bindingや署名・NameID条件をチェックシートへ記入します。
2. SP Metadataを相手へ登録します。IdP署名証明書とSP公開証明書の役割を取り違えないようにします。
3. Provider、IdP Entity ID、SSO/SLO URL、証明書、NameID形式、属性Mappingを変更します。
4. 公開SP URLを変更する場合はAPP_URL、ACS、SLS、必要に応じてSP Entity IDも更新します。
5. 既存ユーザーの外部ID移行を計画してからユーザー照合を有効にします。
6. 段階B〜Gを新しい接続先で再実行します。

IdPのURL変更でEntity IDも変わると、このPoCのユーザー照合キーも変わります。同じ認証元の移設だと確認できた場合のみ、対象の紐付けを移行します。異なるIdPへ一括でIssuerを書き換える運用にはしません。

ローカルの初回Metadata取得は開発専用です。導入先では管理者から受け取った信頼済みIdP証明書を配置し、HTTPS、Cookie、プロキシ配下の公開URL、証明書更新、利用者権限を確認します。別ドメインIdPのACS POSTは、HTTPSでSecure CookieとSameSite=Noneの適用を確認します。

**完了条件：** 導入先のURL・ユーザー・権限で正常系と異常系に通り、鍵更新・設定変更・障害時の担当を決められること。

## 11. 手順書・記事にまとめるときの章立て

1. **作るものと目的**：LaravelをSP、ローカルKeycloakをIdPにし、別IdPへ移せる構成にする。
2. **用語と認証フロー**：Entity ID、ACS、署名、NameIDを実際のURLと関連付けて説明する。
3. **環境構築**：Docker構成、初回起動、ポート、起動成功の確認結果を示す。
4. **IdP/SPの設定交換**：Realm/Client、Metadata、公開証明書、Attribute Mapperを説明する。
5. **ログイン要求からACSまで**：ControllerとServiceの責務、要求ID、署名・各条件の検証を追う。
6. **アプリユーザーへ紐付ける**：NameIDとIssuer、自動作成、既存メールの扱いを説明する。
7. **失敗させて確認する**：正常署名の異常系fixture、再送、不正紐付けの拒否を示す。
8. **ログアウトを確認する**：同じCookieでの再ログインがなぜ確認になるか説明する。
9. **別システムへの適用**：そのまま使う設計と、業務規則に合わせて作り替える部分を示す。
10. **検証結果と残課題**：実行環境・バージョン・証拠と、未検証項目を分けて記録する。

記事では「予定」「動作確認済み」「相手の仕様次第」を区別してください。XMLや画面を掲載する場合は、実在ユーザー情報、セッション情報、実環境の資格情報や秘密鍵を載せず、テストデータへ置き換えます。

## 12. 現時点から次に進める作業

| 状態 | 次にやること | 残す証拠 |
| --- | --- | --- |
| KeycloakのPoCは確認済み | 導入先のユーザー識別・権限規則をチェックシートへ記入 | 仕様・担当・未決事項 |
| Windows実機は未確認 | Linuxコンテナで初回起動から段階Gまで実施 | OS/Dockerバージョン、ログ、画面 |
| IceWall実機は未接続 | Metadata・証明書・属性・Bindingの仕様を入手 | 合意した設定値と制限 |
| 導入先の紐付け方式が決まった | Resolver/DB/guardを調整してユーザー試験を追加 | 正常系・拒否系の結果 |
| 新IdPでログインできた | 属性、権限、不正応答拒否、両側Logoutを検証 | 試験表と検証結果 |
| 運用へ進む | HTTPS、鍵更新、監視、ユーザー停止、切り戻しを検証 | 運用手順と担当 |

各段階で得た結果を[導入チェックシート](INTEGRATION_CHECKLIST.md)へ記録すると、手順書の完了条件や記事の検証結果へそのまま転記できます。
