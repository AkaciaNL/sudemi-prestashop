{*
 * Shipping > sudemi shipping: the shop's shipping settings on sudemi and what buyers pay now.
 * Everything printed is escaped; the form carries the module's CSRF token.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="sudemi-ui" id="sudemi-shipping">
{include file="./sudemi-header.tpl"}
{if !$sudemi.connected}
  <div class="alert alert-warning">{$sudemi_t.not_connected|escape:'html':'UTF-8'}</div>
{elseif $sudemi.shipping}
  <div class="panel">
    <div class="panel-heading">{$sudemi_t.ship_title|escape:'html':'UTF-8'}</div>
    <p>{$sudemi_t.ship_intro|escape:'html':'UTF-8'} {$sudemi.currency_note|escape:'html':'UTF-8'}</p>
    <p><a class="btn btn-default" href="{$sudemi.prefill_url|escape:'html':'UTF-8'}" id="sudemi-prefill">{$sudemi_t.ship_prefill|escape:'html':'UTF-8'}</a></p>
    <form method="post" action="{$sudemi.action_url|escape:'html':'UTF-8'}" id="sudemi-shipping-form">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      <div class="form-group">
        <strong>{$sudemi_t.ship_mode|escape:'html':'UTF-8'}</strong>
        <div class="radio"><label><input type="radio" name="sudemi_mode" value="shop"{if $sudemi.mode == 'shop'} checked{/if} /> {$sudemi_t.ship_mode_shop|escape:'html':'UTF-8'}</label></div>
        <div class="radio"><label><input type="radio" name="sudemi_mode" value="custom"{if $sudemi.mode == 'custom'} checked{/if} /> {$sudemi_t.ship_mode_custom|escape:'html':'UTF-8'}</label></div>
      </div>
      <div class="table-responsive">
      <table class="table" id="sudemi-shipping-table">
        <thead><tr>
          <th>{$sudemi_t.ship_country|escape:'html':'UTF-8'}</th>
          <th>{$sudemi_t.ship_offered|escape:'html':'UTF-8'}</th>
          <th>{$sudemi_t.ship_price|escape:'html':'UTF-8'} ({$sudemi.currency|escape:'html':'UTF-8'})<br /><small>{$sudemi_t.ship_price_help|escape:'html':'UTF-8'}</small></th>
          <th>{$sudemi_t.ship_shop_price|escape:'html':'UTF-8'}</th>
          <th>{$sudemi_t.ship_effective|escape:'html':'UTF-8'}</th>
        </tr></thead>
        <tbody>
        {foreach from=$sudemi.rows item=r}
          <tr data-country="{$r.country|escape:'html':'UTF-8'}" data-offered="{if $r.offered}1{else}0{/if}">
            <td>{$r.country|escape:'html':'UTF-8'}</td>
            <td><input type="checkbox" name="sudemi_enabled[{$r.country|escape:'html':'UTF-8'}]" value="1"{if $r.enabled} checked{/if} /></td>
            <td><input type="text" class="form-control input-sm" name="sudemi_price[{$r.country|escape:'html':'UTF-8'}]" value="{$r.price|escape:'html':'UTF-8'}" inputmode="decimal" maxlength="12" /></td>
            <td>{$r.shop_price|escape:'html':'UTF-8'}</td>
            <td class="sudemi-effective">{$r.effective|escape:'html':'UTF-8'}</td>
          </tr>
        {/foreach}
        </tbody>
      </table>
      </div>
      <button type="submit" name="sudemi_ship_save" value="1" class="btn btn-primary">{$sudemi_t.ship_save|escape:'html':'UTF-8'}</button>
    </form>
  </div>
{/if}
</div>
