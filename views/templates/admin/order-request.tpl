{*
 * The order page block for a sudemi order with an open return request. The buyer's reason is
 * plain text from sudemi: escaped, never HTML. The forms post to the module page, which checks
 * the employee's permission and the CSRF token before anything is sent.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 *}
<div class="card mt-2 panel" id="sudemi-order-request">
  <div class="card-header panel-heading"><h3 class="card-header-title">{$sudemi_rq_labels.block_title|escape:'html':'UTF-8'}</h3></div>
  <div class="card-body">
    <p>{$sudemi_rq_labels.block_intro|escape:'html':'UTF-8'}</p>
    <table class="table">
      <tr><th style="width:14rem">{$sudemi_rq_labels.reason|escape:'html':'UTF-8'}</th>
        <td style="white-space:pre-wrap;word-wrap:break-word">{if $sudemi_rq.reason}{$sudemi_rq.reason|escape:'html':'UTF-8'}{else}<em>{$sudemi_rq_labels.no_reason|escape:'html':'UTF-8'}</em>{/if}</td></tr>
      <tr><th>{$sudemi_rq_labels.requested|escape:'html':'UTF-8'}</th><td>{$sudemi_rq.requested|escape:'html':'UTF-8'}</td></tr>
      <tr><th>{$sudemi_rq_labels.respond_by|escape:'html':'UTF-8'}</th><td><strong>{$sudemi_rq.respond_by|escape:'html':'UTF-8'}</strong></td></tr>
    </table>
    <form method="post" action="{$sudemi_rq_action|escape:'html':'UTF-8'}" style="display:inline-block;margin-right:1em">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      <input type="hidden" name="sudemi_request_order" value="{$sudemi_rq.order_id|escape:'html':'UTF-8'}" />
      <button type="submit" name="sudemi_request_accept" class="btn btn-primary">{$sudemi_rq_labels.accept|escape:'html':'UTF-8'}</button>
    </form>
    <form method="post" action="{$sudemi_rq_action|escape:'html':'UTF-8'}" style="margin-top:1em">
      <input type="hidden" name="sudemi_token" value="{$sudemi_token|escape:'html':'UTF-8'}" />
      <input type="hidden" name="sudemi_request_order" value="{$sudemi_rq.order_id|escape:'html':'UTF-8'}" />
      <div class="form-group">
        <label for="sudemi-request-reason">{$sudemi_rq_labels.reject_reason|escape:'html':'UTF-8'}</label>
        <textarea id="sudemi-request-reason" name="sudemi_request_reason" rows="3" maxlength="1000" required class="form-control"></textarea>
      </div>
      <button type="submit" name="sudemi_request_reject" class="btn btn-default btn-outline-danger">{$sudemi_rq_labels.reject|escape:'html':'UTF-8'}</button>
    </form>
  </div>
</div>
