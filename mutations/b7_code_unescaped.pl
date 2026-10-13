# code_error(): the submitted code reaches the message unescaped.
s~'code' => s\(\$code\),~'code' => \$code,~s;
