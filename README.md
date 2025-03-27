<p align="center">
  <img width="80" height="80" src="logo.png"/>
  <h3 align="center"><a href="https://whispphp.com">Whisp</a></h3>
  <h4 align="center">Your pure* PHP SSH server & the best way to build SSH based TUIs</h4>
      <p align="center">
        <a href="https://github.com/whispphp/whisp/actions"><img alt="GitHub Workflow Status (master)" src="https://img.shields.io/github/actions/workflow/status/whispphp/whisp/tests.yml?branch=main&label=Tests"></a>
        <a href="https://packagist.org/packages/whispphp/whisp"><img alt="Latest Version" src="https://img.shields.io/packagist/v/whispphp/whisp"></a>
        <a href="https://packagist.org/packages/whispphp/whisp"><img alt="License" src="https://img.shields.io/packagist/l/whispphp/whisp"></a>
    </p>
</p>

> [!NOTE]
> Quick example: **sign our guestbook** ([See the code](https://github.com/WhispPHP/whisp/blob/main/examples/guestbook.php))
> ```bash
> ssh guestbook@whisp.fyi
> ```

Explore the docs at **[WhispPHP.com »](https://whispphp.com)**

# Installation

```bash
composer require whispphp/whisp
```

### Requirements
- PHP 8.1+
- FFI module installed and enabled (!)
- pcntl module
- libsodium module

# Usage

Run the server on the port & IP you'd like, with the apps you want to make available. Each connection is forked to its own process and runs independently.

```php
<?php

use Whisp\Server;

$server = new Server(port: 2222);

// Available apps - each is its own script forked
$server->run(apps: [
    'default' => __DIR__.'/examples/howdy.php',
    'guestbook' => __DIR__.'/examples/guestbook.php',
]);

// $server->run('full-path/howdy.php'); // Pass just one path if you'd like to only support 1 default script
```

Once running you can test with:

**Default app**
```bash
ssh localhost -p2222
```

**Guestbook app**
```bash
ssh guestbook@localhost -p2222
```

## Environment variables available to each app
These are each available as environment variables.

| Variable | Description | Notes |
|----------|-------------|------|
| WHISP_APP | The name of the app being requested | |
| WHISP_CLIENT_IP | The IP address of the connecting client | |
| WHISP_TTY | The TTY information for the connection | e.g. /dev/ttys072 |
| WHISP_USERNAME | The username used in the SSH connection | Unavailable when no username passed, or when the username is a valid app |


## Clients requesting apps
There are two ways for clients to request an available app:
1. **Username method:** `ssh app@server` - we use the 'username' here as the app name if it matches an available app.
    - Much cleaner, but means if you need the username for auth you can't define the app this way
2. **Command method:** `ssh server -t app` - request an interactive shell (`-t`) with the requested `app`


---


# Examples

**Play the Dinorun game** ∙ [See the code](https://github.com/WhispPHP/whisp/blob/main/examples/dinorun.php)
```bash
ssh dinorun@whisp.fyi
```

**View your sunrise/sunset times** ∙ [See the code](https://github.com/WhispPHP/whisp/blob/main/examples/daylight.php)
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
