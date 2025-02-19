<table class="crm-info-panel">
        {foreach from=$extension.urls key=label item=url}
            <tr><td class="label">{$label}</td><td><a href="{$url}">{$url}</a></td></tr>
        {/foreach}
    <tr>
        <td class="label">{ts}Author{/ts}</td>
        <td>
          {foreach from=$extension.authors item=author}
            {capture assign=authorDetails}
              {if $author.role}{$author.role|escape};{/if}
              {if $author.email}<a href="mailto:{$author.email|escape}">{$author.email|escape}</a>;{/if}
              {if $author.homepage}<a href="{$author.homepage|escape}">{$author.homepage|escape}</a>;{/if}
            {/capture}
            {$author.name|escape} {if $authorDetails}({$authorDetails|trim:'; '}){/if}<br/>
          {/foreach}
        </td>
    </tr>
    <tr>
      <td class="label">{ts}Comments{/ts}</td><td>{$extension.comments}</td>
    </tr>
    <tr>
        <td class="label">{ts}Version{/ts}</td><td>{$extension.version}</td>
    </tr>
    <tr>
        <td class="label">{ts}Released on{/ts}</td><td>{$extension.releaseDate}</td>
    </tr>
    <tr>
        <td class="label">{ts}License{/ts}</td><td>{$extension.license}</td>
    </tr>
    <tr>
        <td class="label">{ts}Development stage{/ts}</td><td>{$extension.develStage}</td>
    </tr>
    <tr>
        <td class="label">{ts}Requires{/ts}</td>
        <td>
            {foreach from=$extension.requires item=ext}
                {if array_key_exists($ext, $localExtensionRows)}
                    {$localExtensionRows.$ext.name} (already downloaded - {$ext})
                {elseif array_key_exists($ext, $remoteExtensionRows)}
                    {$remoteExtensionRows.$ext.name} (not downloaded - {$ext})
                {else}
                    {$ext} {ts}(not available){/ts}
                {/if}
                <br/>
            {/foreach}
        </td>
    </tr>
    <tr>
        <td class="label">{ts}Compatible with{/ts}</td>
        <td>
            {foreach from=$extension.compatibility.ver item=ver}
                {$ver} &nbsp;
            {/foreach}
        </td>
    </tr>
    <tr>
      <td class="label">{ts}Local path{/ts}</td><td>{$extension.path}</td>
    </tr>
    <tr>
      <td class="label">{ts}Download location{/ts}</td><td>{$extension.downloadUrl}</td>
    </tr>
    <tr>
      <td class="label">{ts}Key{/ts}</td><td>{$extension.key}</td>
    </tr>
</table>
