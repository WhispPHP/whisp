# Whisp - Pure PHP SSH Server

<p align="center">
  <img width="200" height="200" src="logo.png"/>
  <br/>
  <a href="https://whispphp.com">View Full Docs</a>
  <br/>
  SSH server built in pure* PHP - the best way to build SSH based PHP apps.
</p>

> [!NOTE]
> Quick examples -> **sign our guestbook** or **play dinosaur run**:
```bash
ssh guestbook@whisp.fyi
```
```bash
ssh dinorun@whisp.fyi
```

<br/>

# Installation

Install the package via composer:

```bash
composer require whispphp/whisp
```

<br/>

# Usage

You 'run' the server with the apps you want to make available.

Each app is ran as its own separate script, so you can test it normally on the CLI too.
```php
<?php

require __DIR__.'/vendor/autoload.php';

use Whisp\Server;

$server = new Server(port: 2222);

$server->run([
    'default' => __DIR__.'/examples/howdy.php',
    'guestbook' => __DIR__.'/examples/guestbook.php',
]);

// $server->run(__DIR__.'/examples/howdy.php'); // Pass just one path if you'd like to only support 1 default script
```

Test with `ssh localhost -p2222` (to run the default app) or `ssh guestbook@localhost -p2222` or `ssh localhost -p2222 -t guestbook` to run the guestbook app.


// Environment variables from the SSH client are available in the $_ENV or $_SERVER array
/*
Whisp also adds: WHISP_APP, WHISP_CLIENT_IP, WHISP_TTY, WHISP_USERNAME

## Example server code

## How to change server's ssh server to a diff. port

## How to setup systemd so whisp server always running on port 22

Lots of this should be on whisp.fyi docs site tbf

## Setting requested app
Two options for loading the correct app:
1. `ssh app@server` - we use the 'username' here as the app name if it matches an available app.
  - Much cleaner, but means if you need the username for auth you can't define the app this way
2. `ssh server -t app`


# Future
- [ ] Add chacha support so we don't require AES-256 on the server (`sodium_crypto_aead_aes256gcm_is_available`)
- [ ] Enable hooking into userauth
- [ ] Simplify WinSize and TerminalInfo

---


# Examples
**Sign the Whisp Guestbook**
([See the code](https://github.com/WhispPHP/whisp/blob/main/examples/guestbook.php))
```bash
ssh guestbook@whisp.fyi
```

**Play the Dinorun game**
[See the code](https://github.com/WhispPHP/whisp/blob/main/examples/dinorun.php)
```bash
ssh dinorun@whisp.fyi
```

**Sunrise/sunset**
[See the code](https://github.com/WhispPHP/whisp/blob/main/examples/daylight.php)
```bash
ssh daylight@whisp.fyi
```

**Find your closest World Heritage Sites**
```bash
ssh elec@whisp.fyi
```

---

## Support & Credits

This was developed by Ashley Hindle. If you like it, please star it, share it, and let me know!

- [Bluesky](https://bsky.app/profile/ashleyhindle.com)
- [Twitter](https://twitter.com/ashleyhindle)
- Website [https://ashleyhindle.com](https://ashleyhindle.com)
