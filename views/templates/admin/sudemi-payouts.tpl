{*
 * sudemi > Payouts (1.4.0): what sudemi paid this shop and what is still due (GET /payouts).
 * Everything printed is escaped.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="sudemi-ui" id="sudemi-payouts-page">
{include file="./sudemi-header.tpl"}
{if !$sudemi.connected}
  <div class="sudemi-card"><div class="sudemi-card-body"><p class="sudemi-empty">{$sudemi_t.not_connected|escape:'html':'UTF-8'}</p></div></div>
{elseif $sudemi.payouts}
  <p class="sudemi-intro">{$sudemi_t.payouts_intro|escape:'html':'UTF-8'}</p>
  <div class="sudemi-card">
    <div class="sudemi-card-head"><h3>{$sudemi_t.payouts_account|escape:'html':'UTF-8'}</h3>
      <span class="sudemi-badge {if $sudemi.payouts.account_state == 'ACTIVE'}sudemi-badge-ok{else}sudemi-badge-warn{/if}" id="sudemi-account-state">{$sudemi.payouts.account|escape:'html':'UTF-8'}</span></div>
    <div class="sudemi-card-body"><p class="sudemi-muted" style="margin:0">{$sudemi.payouts.delay|escape:'html':'UTF-8'}</p></div>
  </div>
  {foreach from=$sudemi.payouts.rows item=p}
    <div class="sudemi-kpis" data-currency="{$p.currency|escape:'html':'UTF-8'}">
      <div class="sudemi-kpi sudemi-kpi-main"><p class="sudemi-kpi-label">{$sudemi_t.payouts_paid|escape:'html':'UTF-8'}</p><p class="sudemi-kpi-value">{$p.paid|escape:'html':'UTF-8'}</p></div>
      <div class="sudemi-kpi sudemi-kpi-main"><p class="sudemi-kpi-label">{$sudemi_t.payouts_outstanding|escape:'html':'UTF-8'}</p><p class="sudemi-kpi-value">{$p.outstanding|escape:'html':'UTF-8'}</p></div>
      <div class="sudemi-kpi"><p class="sudemi-kpi-label">{$sudemi_t.payouts_scheduled|escape:'html':'UTF-8'}</p><p class="sudemi-kpi-value">{$p.scheduled|escape:'html':'UTF-8'}</p>{if $p.next}<p class="sudemi-kpi-help">{$sudemi_t.payouts_next|escape:'html':'UTF-8'}: {$p.next|escape:'html':'UTF-8'}</p>{/if}</div>
      <div class="sudemi-kpi"><p class="sudemi-kpi-label">{$sudemi_t.payouts_waiting|escape:'html':'UTF-8'}</p><p class="sudemi-kpi-value">{$p.waiting|escape:'html':'UTF-8'}</p></div>
      <div class="sudemi-kpi"><p class="sudemi-kpi-label">{$sudemi_t.payouts_processing|escape:'html':'UTF-8'}</p><p class="sudemi-kpi-value">{$p.processing|escape:'html':'UTF-8'}</p></div>
      <div class="sudemi-kpi"><p class="sudemi-kpi-label">{$sudemi_t.payouts_held|escape:'html':'UTF-8'}</p><p class="sudemi-kpi-value">{$p.held|escape:'html':'UTF-8'}</p></div>
    </div>
  {foreachelse}
    <div class="sudemi-card"><div class="sudemi-card-body"><p class="sudemi-empty">{$sudemi_t.payouts_none|escape:'html':'UTF-8'}</p></div></div>
  {/foreach}
  <p><a href="{$sudemi.orders_url|escape:'html':'UTF-8'}">{$sudemi_t.payouts_per_order|escape:'html':'UTF-8'}</a></p>
{/if}
</div>
