{*
 * sudemi > Products on sudemi (1.4.0): the shop's products as sudemi lists them
 * (GET /integration/v1/listings), why one is not shown, and the shop's own on/off switch.
 * Everything printed is escaped; the switch forms carry the module's CSRF token.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="sudemi-ui" id="sudemi-products">
{include file="./sudemi-header.tpl"}
{if !$sudemi.connected}
  <div class="sudemi-card"><div class="sudemi-card-body"><p class="sudemi-empty">{$sudemi_t.not_connected|escape:'html':'UTF-8'}</p></div></div>
{else}
  <p class="sudemi-intro">{$sudemi_t.prod_intro|escape:'html':'UTF-8'}</p>
  <div class="sudemi-card">
    <div class="sudemi-card-head">
      <div class="sudemi-tabs" id="sudemi-listing-tabs">
        {foreach from=$sudemi.tabs item=tb}
          <a href="{$tb.url|escape:'html':'UTF-8'}" class="{if $tb.active}active{/if}" data-status="{$tb.key|escape:'html':'UTF-8'}">{$tb.label|escape:'html':'UTF-8'}</a>
        {/foreach}
      </div>
      <form method="get" action="index.php" class="form-inline" style="display:flex;gap:6px">
        <input type="hidden" name="controller" value="{$sudemi.form_controller|escape:'html':'UTF-8'}" />
        <input type="hidden" name="token" value="{$sudemi.form_token|escape:'html':'UTF-8'}" />
        {if $sudemi.status != 'all'}<input type="hidden" name="status" value="{$sudemi.status|escape:'html':'UTF-8'}" />{/if}
        <input type="search" name="q" class="form-control" maxlength="100" placeholder="{$sudemi_t.search_products|escape:'html':'UTF-8'}" value="{$sudemi.q|escape:'html':'UTF-8'}" />
        <button type="submit" class="btn btn-primary">{$sudemi_t.search|escape:'html':'UTF-8'}</button>
      </form>
    </div>
    {if $sudemi.rows}
      <div class="sudemi-scroll"><table class="sudemi-table" id="sudemi-listing-table">
        <thead><tr>
          <th class="sudemi-col-product">{$sudemi_t.col_product|escape:'html':'UTF-8'}</th>
          <th class="num">{$sudemi_t.col_price|escape:'html':'UTF-8'}</th>
          <th class="num">{$sudemi_t.col_stock|escape:'html':'UTF-8'}</th>
          <th class="num">{$sudemi_t.col_photos|escape:'html':'UTF-8'}</th>
          <th class="num">{$sudemi_t.col_countries|escape:'html':'UTF-8'}</th>
          <th class="num">{$sudemi_t.col_shown30|escape:'html':'UTF-8'}</th>
          <th class="num">{$sudemi_t.col_views30|escape:'html':'UTF-8'}</th>
          <th class="num">{$sudemi_t.col_orders30|escape:'html':'UTF-8'}</th>
          <th>{$sudemi_t.col_on_sudemi|escape:'html':'UTF-8'}</th>
          <th></th>
        </tr></thead>
        <tbody>
        {foreach from=$sudemi.rows item=r}
          <tr data-listing="{$r.external_id|escape:'html':'UTF-8'}" data-listed="{if $r.listed}1{else}0{/if}">
            <td>
              {if $r.edit_url}<a href="{$r.edit_url|escape:'html':'UTF-8'}">{$r.title|escape:'html':'UTF-8'}</a>{else}{$r.title|escape:'html':'UTF-8'}{/if}
              <br><small class="sudemi-muted">{if $r.sku}{$r.sku|escape:'html':'UTF-8'} &middot; {/if}ID {$r.external_id|escape:'html':'UTF-8'}</small>
            </td>
            <td class="num">{$r.price|escape:'html':'UTF-8'}</td>
            <td class="num">{$r.stock|escape:'html':'UTF-8'}</td>
            <td class="num">{$r.images|intval}</td>
            <td class="num">{$r.countries|intval}</td>
            <td class="num">{$r.impressions|escape:'html':'UTF-8'}</td>
            <td class="num">{$r.views|escape:'html':'UTF-8'}</td>
            <td class="num">{$r.orders|escape:'html':'UTF-8'}</td>
            <td>
              {if $r.listed}<span class="sudemi-badge sudemi-badge-ok">{$sudemi_t.state_listed|escape:'html':'UTF-8'}</span>
              {else}<span class="sudemi-badge sudemi-badge-warn">{$sudemi_t.state_not_listed|escape:'html':'UTF-8'}</span>
                <ul class="sudemi-reasons">{foreach from=$r.reasons item=reason}<li>{$reason|escape:'html':'UTF-8'}</li>{/foreach}</ul>
              {/if}
            </td>
            <td>
              <form method="post" action="{$sudemi.self_url|escape:'html':'UTF-8'}" style="margin:0">
                <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
                <input type="hidden" name="sudemi_listing" value="{$r.external_id|escape:'html':'UTF-8'}" />
                <input type="hidden" name="sudemi_title" value="{$r.title|escape:'html':'UTF-8'}" />
                {if $r.enabled}
                  <button type="submit" name="sudemi_switch" value="off" class="sudemi-switch">{$sudemi_t.switch_off|escape:'html':'UTF-8'}</button>
                {else}
                  <button type="submit" name="sudemi_switch" value="on" class="sudemi-switch sudemi-switch-on">{$sudemi_t.switch_on|escape:'html':'UTF-8'}</button>
                {/if}
              </form>
            </td>
          </tr>
        {/foreach}
        </tbody>
      </table></div>
      <div class="sudemi-pager">
        <span>{$sudemi.shown|escape:'html':'UTF-8'}</span>
        <span>
          {if $sudemi.prev_url}<a class="btn btn-default" href="{$sudemi.prev_url|escape:'html':'UTF-8'}">{$sudemi_t.previous|escape:'html':'UTF-8'}</a>{/if}
          {if $sudemi.next_url}<a class="btn btn-default" href="{$sudemi.next_url|escape:'html':'UTF-8'}">{$sudemi_t.next|escape:'html':'UTF-8'}</a>{/if}
        </span>
      </div>
    {elseif $sudemi.ok}
      <p class="sudemi-empty">{$sudemi_t.prod_none|escape:'html':'UTF-8'}</p>
    {/if}
  </div>
  <p class="sudemi-muted" style="font-size:12px">{$sudemi_t.switch_help|escape:'html':'UTF-8'}</p>
{/if}
</div>
