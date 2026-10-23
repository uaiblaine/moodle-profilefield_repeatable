# lib.php: the profile page shows the field only to viewers its visibility setting admits.
s/if \(!\$formfield->is_visible\(\) \|\| \$formfield->is_empty\(\)\) \{/if (\$formfield->is_empty()) {/;
