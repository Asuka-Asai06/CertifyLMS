
{{--
    管理者お知らせメール本文（HTML / テキスト両方をレンダリングするマークダウンメール）。
    構成: 見出し → お知らせ本文 → お知らせ詳細ボタン → 署名。
    画面ではなくメールクライアントで表示される（共通レイアウト非継承、Mail のマークダウンコンポーネントを使用）。
--}}
@component('mail::message')
# Certify LMS からのお知らせ

{{ $recipientName }} 様

運営からのお知らせをお届けします。

## {{ $announcement->title }}

<div style="white-space: pre-wrap;">{{ $announcement->body }}</div>

@component('mail::button', ['url' => $notificationUrl])
お知らせを確認する
@endcomponent

Certify LMS 運営チーム
@endcomponent
