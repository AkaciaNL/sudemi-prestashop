{*
 * The top of every sudemi page (1.4.0): the sudemi mark, the shop, whether sudemi shows its
 * products, and the sudemi pages. Styles for all sudemi pages are here, scoped to .sudemi-ui.
 * Everything printed is escaped.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<style>
.sudemi-ui{ldelim}--sd-accent:#2b59d6;--sd-accent-soft:#eaefff;--sd-ink:#1f2933;--sd-muted:#5b6876;--sd-line:#e3e8ee;--sd-ok:#1e7d4f;--sd-ok-soft:#e5f4ec;--sd-warn:#9a5b00;--sd-warn-soft:#fff3dd;--sd-bad:#b42318;--sd-bad-soft:#fdecea;color:var(--sd-ink);font-size:14px;line-height:1.5{rdelim}
.sudemi-ui *{ldelim}box-sizing:border-box{rdelim}
.sudemi-top{ldelim}display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:#fff;border:1px solid var(--sd-line);border-radius:10px 10px 0 0;padding:16px 20px{rdelim}
.sudemi-brand{ldelim}display:flex;align-items:center;gap:12px;min-width:0{rdelim}
.sudemi-brand svg{ldelim}flex:0 0 auto{rdelim}
.sudemi-brand-name{ldelim}font-size:18px;font-weight:600;line-height:1.2;margin:0{rdelim}
.sudemi-brand-shop{ldelim}color:var(--sd-muted);font-size:13px;margin:0;overflow:hidden;text-overflow:ellipsis{rdelim}
.sudemi-pill{ldelim}display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 12px;font-size:13px;font-weight:600;white-space:nowrap{rdelim}
.sudemi-pill:before{ldelim}content:"";width:8px;height:8px;border-radius:50%;background:currentColor{rdelim}
.sudemi-pill-ok{ldelim}background:var(--sd-ok-soft);color:var(--sd-ok){rdelim}
.sudemi-pill-warn{ldelim}background:var(--sd-warn-soft);color:var(--sd-warn){rdelim}
.sudemi-pill-off{ldelim}background:#eef1f4;color:var(--sd-muted){rdelim}
.sudemi-status{ldelim}text-align:end;max-width:460px{rdelim}
.sudemi-status small{ldelim}display:block;color:var(--sd-muted);margin-top:4px{rdelim}
.sudemi-nav{ldelim}display:flex;flex-wrap:wrap;gap:2px;background:#fff;border:1px solid var(--sd-line);border-top:0;border-radius:0 0 10px 10px;padding:0 12px;margin:0 0 20px{rdelim}
.sudemi-nav a{ldelim}display:inline-flex;align-items:center;gap:6px;padding:12px 10px;color:var(--sd-muted);text-decoration:none;border-bottom:3px solid transparent;font-weight:500{rdelim}
.sudemi-nav a:hover{ldelim}color:var(--sd-ink){rdelim}
.sudemi-ui .sudemi-nav a,.sudemi-ui .sudemi-tabs a,.sudemi-ui .sudemi-nav a:hover,.sudemi-ui .sudemi-tabs a:hover,.sudemi-ui .sudemi-nav a:focus{ldelim}text-decoration:none!important{rdelim}
.sudemi-nav a.active{ldelim}color:var(--sd-accent);border-bottom-color:var(--sd-accent){rdelim}
.sudemi-nav .material-icons{ldelim}font-size:18px{rdelim}
.sudemi-card{ldelim}background:#fff;border:1px solid var(--sd-line);border-radius:10px;margin:0 0 20px;overflow:hidden{rdelim}
.sudemi-card-head{ldelim}display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 20px;border-bottom:1px solid var(--sd-line){rdelim}
.sudemi-card-head h3{ldelim}margin:0;font-size:15px;font-weight:600{rdelim}
.sudemi-card-body{ldelim}padding:16px 20px{rdelim}
.sudemi-card-body > :last-child{ldelim}margin-bottom:0{rdelim}
.sudemi-intro{ldelim}color:var(--sd-muted);margin:0 0 16px{rdelim}
.sudemi-kpis{ldelim}display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 20px{rdelim}
.sudemi-kpi{ldelim}background:#fff;border:1px solid var(--sd-line);border-radius:10px;padding:14px 16px{rdelim}
.sudemi-kpi-label{ldelim}color:var(--sd-muted);font-size:13px;margin:0{rdelim}
.sudemi-kpi-value{ldelim}font-size:24px;font-weight:600;margin:4px 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis{rdelim}
.sudemi-kpi-help{ldelim}color:var(--sd-muted);font-size:12px;margin:0{rdelim}
.sudemi-kpi-main .sudemi-kpi-value{ldelim}color:var(--sd-accent){rdelim}
.sudemi-grid2{ldelim}display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px{rdelim}
.sudemi-chart{ldelim}width:100%;height:220px;display:block{rdelim}
.sudemi-chart-grid{ldelim}stroke:#edf0f3;stroke-width:1{rdelim}
.sudemi-chart-axis{ldelim}fill:#7b8794;font-size:11px{rdelim}
.sudemi-chart-axis-line{ldelim}fill:#c2410c{rdelim}
.sudemi-chart-bar{ldelim}fill:#2b59d6;opacity:.85{rdelim}
.sudemi-chart-bar:hover{ldelim}opacity:1{rdelim}
.sudemi-chart-line{ldelim}fill:none;stroke:#c2410c;stroke-width:2;stroke-linejoin:round;stroke-linecap:round{rdelim}
.sudemi-legend{ldelim}display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--sd-muted){rdelim}
.sudemi-legend span:before{ldelim}content:"";display:inline-block;width:10px;height:10px;border-radius:2px;margin-inline-end:6px;vertical-align:-1px{rdelim}
.sudemi-legend .bar:before{ldelim}background:#2b59d6{rdelim}
.sudemi-legend .line:before{ldelim}background:#c2410c;height:3px;vertical-align:3px{rdelim}
.sudemi-table{ldelim}width:100%;border-collapse:collapse;margin:0{rdelim}
.sudemi-table th{ldelim}text-align:start;font-weight:600;color:var(--sd-muted);font-size:12px;text-transform:uppercase;letter-spacing:.02em;padding:10px 12px;border-bottom:1px solid var(--sd-line);background:#f8fafc;white-space:nowrap{rdelim}
.sudemi-table td{ldelim}padding:10px 12px;border-bottom:1px solid var(--sd-line);vertical-align:top{rdelim}
.sudemi-table tr:last-child td{ldelim}border-bottom:0{rdelim}
.sudemi-table .num{ldelim}text-align:end;white-space:nowrap;font-variant-numeric:tabular-nums{rdelim}
.sudemi-scroll{ldelim}overflow-x:auto{rdelim}
.sudemi-badge{ldelim}display:inline-block;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:600;white-space:nowrap;background:#eef1f4;color:var(--sd-muted){rdelim}
.sudemi-badge-ok{ldelim}background:var(--sd-ok-soft);color:var(--sd-ok){rdelim}
.sudemi-badge-warn{ldelim}background:var(--sd-warn-soft);color:var(--sd-warn){rdelim}
.sudemi-badge-bad{ldelim}background:var(--sd-bad-soft);color:var(--sd-bad){rdelim}
.sudemi-badge-info{ldelim}background:var(--sd-accent-soft);color:var(--sd-accent){rdelim}
.sudemi-reasons{ldelim}margin:4px 0 0;padding:0;list-style:none;color:var(--sd-warn);font-size:12px{rdelim}
.sudemi-tabs{ldelim}display:flex;gap:6px;flex-wrap:wrap{rdelim}
.sudemi-tabs a{ldelim}padding:6px 12px;border-radius:999px;border:1px solid var(--sd-line);color:var(--sd-muted);text-decoration:none;font-weight:500{rdelim}
.sudemi-tabs a.active{ldelim}background:var(--sd-accent);border-color:var(--sd-accent);color:#fff{rdelim}
.sudemi-bar{ldelim}height:8px;border-radius:999px;background:#edf0f3;overflow:hidden;margin:8px 0{rdelim}
.sudemi-bar span{ldelim}display:block;height:100%;background:var(--sd-ok){rdelim}
.sudemi-muted{ldelim}color:var(--sd-muted){rdelim}
.sudemi-empty{ldelim}color:var(--sd-muted);padding:24px 0;text-align:center;margin:0{rdelim}
.sudemi-pager{ldelim}display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 20px;border-top:1px solid var(--sd-line);color:var(--sd-muted){rdelim}
.sudemi-form-row{ldelim}margin:0 0 16px{rdelim}
.sudemi-form-row label{ldelim}display:block;font-weight:600;margin:0 0 4px{rdelim}
.sudemi-form-row .help{ldelim}color:var(--sd-muted);font-size:12px;margin:4px 0 0{rdelim}
.sudemi-dl{ldelim}display:grid;grid-template-columns:max-content minmax(0,1fr);gap:8px 20px;margin:0{rdelim}
.sudemi-dl dt{ldelim}color:var(--sd-muted);font-weight:500{rdelim}
.sudemi-dl dd{ldelim}margin:0;word-break:break-word{rdelim}
.sudemi-btn-sm{ldelim}padding:3px 10px;font-size:12px{rdelim}
.sudemi-ui .sudemi-switch{ldelim}display:inline-block;white-space:nowrap;border:1px solid var(--sd-line);background:#fff;color:var(--sd-ink);border-radius:6px;padding:4px 10px;font-size:12px;font-weight:600;line-height:1.4;cursor:pointer{rdelim}
.sudemi-ui .sudemi-switch:hover{ldelim}border-color:var(--sd-accent);color:var(--sd-accent){rdelim}
.sudemi-ui .sudemi-switch-on{ldelim}background:var(--sd-accent);border-color:var(--sd-accent);color:#fff{rdelim}
.sudemi-ui .sudemi-switch-on:hover{ldelim}color:#fff;opacity:.9{rdelim}
.sudemi-col-product{ldelim}min-width:240px{rdelim}
.sudemi-ui .btn-primary{ldelim}background:var(--sd-accent);border-color:var(--sd-accent){rdelim}
.sudemi-ui .panel{ldelim}background:#fff;border:1px solid var(--sd-line);border-radius:10px;box-shadow:none;padding:0 20px 16px!important;margin:0 0 20px;overflow:hidden{rdelim}
.sudemi-ui .panel-heading{ldelim}font-size:15px;font-weight:600;text-transform:none;letter-spacing:0;color:var(--sd-ink);border-bottom:1px solid var(--sd-line)!important;margin:0 -20px 16px!important;padding:14px 20px!important;height:auto!important;line-height:1.4!important;background:transparent!important;position:static!important{rdelim}
.sudemi-ui .panel-heading .icon-shopping-cart,.sudemi-ui .panel-heading .icon-question-sign{ldelim}display:none{rdelim}
.sudemi-ui .table{ldelim}margin-bottom:12px{rdelim}
.sudemi-ui .table > thead > tr > th{ldelim}font-weight:600;color:var(--sd-muted);font-size:12px;text-transform:uppercase;letter-spacing:.02em;background:#f8fafc;border-bottom:1px solid var(--sd-line){rdelim}
.sudemi-ui .table > tbody > tr > td,.sudemi-ui .table > tbody > tr > th{ldelim}border-top:1px solid var(--sd-line);vertical-align:top{rdelim}
.sudemi-ui h4{ldelim}font-size:15px;font-weight:600;margin:24px 0 10px;color:var(--sd-ink){rdelim}
.sudemi-ui .help-block{ldelim}color:var(--sd-muted);font-size:12px{rdelim}
.sudemi-ui fieldset.well{ldelim}background:#f8fafc;border:1px solid var(--sd-line);border-radius:10px;box-shadow:none;padding:16px 20px{rdelim}
.sudemi-ui fieldset.well legend{ldelim}font-size:14px;font-weight:600;border:0;width:auto;margin:0;padding:0 6px{rdelim}
.sudemi-ui code{ldelim}background:#f1f4f8;color:var(--sd-ink);border-radius:4px;padding:1px 6px{rdelim}
.sudemi-ui .alert{ldelim}border-radius:10px{rdelim}
.sudemi-section{ldelim}scroll-margin-top:90px{rdelim}
@media (max-width:1100px){ldelim}.sudemi-kpis{ldelim}grid-template-columns:repeat(2,minmax(0,1fr)){rdelim}.sudemi-grid2{ldelim}grid-template-columns:minmax(0,1fr){rdelim}{rdelim}
@media (max-width:560px){ldelim}.sudemi-kpis{ldelim}grid-template-columns:minmax(0,1fr){rdelim}.sudemi-status{ldelim}text-align:start{rdelim}.sudemi-nav a span.lbl{ldelim}font-size:13px{rdelim}{rdelim}
</style>
<div class="sudemi-top" id="sudemi-header">
  <div class="sudemi-brand">
    <svg width="36" height="36" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="7" fill="#2b59d6"/><g fill="none" stroke="#fff" stroke-width="3.1" stroke-linecap="round" stroke-linejoin="round"><path d="M8 11.2h7.2a4.2 4.2 0 0 1 4.2 4.2v1.2"/><path d="M24 20.8h-7.2a4.2 4.2 0 0 1-4.2-4.2v-1.2"/></g></svg>
    <div>
      <p class="sudemi-brand-name">sudemi{if $sudemi_hdr.title} <span class="sudemi-muted" style="font-weight:400">/ {$sudemi_hdr.title|escape:'html':'UTF-8'}</span>{/if}</p>
      <p class="sudemi-brand-shop">{$sudemi_hdr.shop|escape:'html':'UTF-8'} &middot; {$sudemi_hdr.tagline|escape:'html':'UTF-8'}</p>
    </div>
  </div>
  <div class="sudemi-status">
    <span class="sudemi-pill sudemi-pill-{$sudemi_hdr.state|escape:'html':'UTF-8'}" id="sudemi-state" data-state="{$sudemi_hdr.state|escape:'html':'UTF-8'}">{$sudemi_hdr.label|escape:'html':'UTF-8'}</span>
    {if $sudemi_hdr.reason}<small>{$sudemi_hdr.reason|escape:'html':'UTF-8'}</small>{/if}
  </div>
</div>
<nav class="sudemi-nav" id="sudemi-nav">
  {foreach from=$sudemi_hdr.nav item=n}
    <a href="{$n.url|escape:'html':'UTF-8'}" class="{if $n.active}active{/if}" data-page="{$n.key|escape:'html':'UTF-8'}"><i class="material-icons" aria-hidden="true">{$n.icon|escape:'html':'UTF-8'}</i><span class="lbl">{$n.label|escape:'html':'UTF-8'}</span></a>
  {/foreach}
</nav>
{foreach from=$sudemi_messages item=m}
  <div class="alert alert-{if $m.type == 'error'}danger{elseif $m.type == 'success'}success{elseif $m.type == 'info'}info{else}warning{/if} sudemi-message">{$m.text|escape:'html':'UTF-8'}</div>
{/foreach}
