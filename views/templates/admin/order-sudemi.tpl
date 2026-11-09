{*
 * The sudemi block on PrestaShop's order page: the state on sudemi as last applied, a link to the
 * sudemi order page, and "Send tracking to sudemi" (posted to the sudemi orders page, which checks
 * the employee's permission and the CSRF token before anything is sent).
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="card mt-2 panel" id="sudemi-order-block">
  <div class="card-header panel-heading"><h3 class="card-header-title">{$sudemi_ob.labels.block_title|escape:'html':'UTF-8'}</h3></div>
  <div class="card-body">
    <table class="table">
      <tr><th style="width:14rem">{$sudemi_ob.labels.block_status|escape:'html':'UTF-8'}</th><td id="sudemi-ob-status">{$sudemi_ob.status|escape:'html':'UTF-8'}</td></tr>
      {if $sudemi_ob.tracking}<tr><th>{$sudemi_ob.labels.block_tracking|escape:'html':'UTF-8'}</th><td>{$sudemi_ob.tracking|escape:'html':'UTF-8'}</td></tr>{/if}
    </table>
    <p><a href="{$sudemi_ob.url|escape:'html':'UTF-8'}">{$sudemi_ob.labels.block_open|escape:'html':'UTF-8'}</a></p>
    {if $sudemi_ob.can_track}
      <form method="post" action="{$sudemi_ob.action_url|escape:'html':'UTF-8'}" class="form-inline" id="sudemi-send-tracking">
        <input type="hidden" name="sudemi_token" value="{$sudemi_ob.token|escape:'html':'UTF-8'}" />
        <label>{$sudemi_ob.labels.carrier|escape:'html':'UTF-8'} <input type="text" name="sudemi_carrier" maxlength="100" class="form-control" value="{$sudemi_ob.carrier|escape:'html':'UTF-8'}" /></label>
        <label>{$sudemi_ob.labels.tracking_code|escape:'html':'UTF-8'} <input type="text" name="sudemi_tracking" maxlength="100" required class="form-control" value="{$sudemi_ob.tracking_code|escape:'html':'UTF-8'}" /></label>
        <button type="submit" name="sudemi_do" value="tracking" class="btn btn-primary">{$sudemi_ob.labels.do_tracking|escape:'html':'UTF-8'}</button>
        <p class="help-block">{$sudemi_ob.labels.tracking_help|escape:'html':'UTF-8'}</p>
      </form>
    {/if}
  </div>
</div>
