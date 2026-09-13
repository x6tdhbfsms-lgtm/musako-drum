@extends('layouts.app')
@section('title', 'プライバシーポリシー | MUSAKO')
@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <div><p class="text-sm font-bold tracking-widest text-amber-700">PRIVACY</p><h1 class="mt-2 text-3xl font-bold">プライバシーポリシー</h1></div>
    <section class="space-y-4 rounded-3xl border border-stone-200 bg-white p-6 text-sm leading-7 shadow-sm sm:p-8">
        <p>MUSAKOドラム教室は、体験レッスン・入会手続き・教室運営に必要な範囲で個人情報を取得し、申込み対応、連絡、契約管理のために利用します。</p>
        <p>カード番号、銀行口座番号、暗証番号は本サイトの申込みフォームへ入力しないでください。取得した情報は法令上必要な場合を除き、ご本人の同意なく第三者へ提供しません。</p>
        <p>保存情報の確認・訂正・削除に関するお問い合わせは教室までご連絡ください。</p>
        <p class="text-xs text-stone-500">ポリシーバージョン：{{ config('musako.privacy.policy_version') }}</p>
    </section>
</div>
@endsection
