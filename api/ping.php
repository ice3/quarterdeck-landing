<?php header("Content-Type: text/plain"); echo "php ok ", PHP_VERSION, " curl=", function_exists("curl_init") ? "yes" : "no", "\n";
