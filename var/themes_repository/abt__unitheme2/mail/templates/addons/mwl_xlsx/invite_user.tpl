{capture name="subject"}{__("mwl_xlsx.invite_subject", ["[store]"=>$store_name])}{/capture}
{assign var="subject" value=$smarty.capture.subject scope="parent"}

<p>{__("mwl_xlsx.invite_hello", ["[name]" => "{$firstname}"])|trim}</p>
<p>{__("mwl_xlsx.invite_text", ["[store]" => $store_name])}</p>

<p><a href="{$invite_link}">{__("mwl_xlsx.invite_button")}</a></p>

<p>{__("mwl_xlsx.invite_hint")}</p>
