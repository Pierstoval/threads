Fetch all your own Mastodon threads
===================================

This small command utility helps you finding all your posts that can be considered as threads on your Mastodon instance. It includes your replies, so if you wrote a thread which first post is a reply to another user's post, it will be taken in account too.

The number of posts necessary to consider a list of posts to be a thread is customizable.

## Prerequisites

- PHP 8.4
- Composer

## Install

Run these commands:

```bash
git clone git@github.com:pierstoval/threads.git
cd threads
composer install
cp .env.example .env
```

Then, open the `.env` file and provide the environment variables:

- `APP_INSTANCE`: the domain name of your instance (like `mastodon.social`, `piaille.fr`, `hachyderm.io`, etc.)
- `APP_ACCESS_TOKEN`: an access token that is necessary to connect to your instance's API.<br>
  To get an access token, go to the "Developers" section on your instance, or at this kind of URL: `https://{your instance domain}/settings/applications/new`.<br>
  Create a new application, and give it the `read:account`, `read:statuses` and `profile` permissions.<br> 
  After that, click on the application name in the "Developers" section, and copy the "access token", and that's it!

## Usage

```
> php run.php --help
Usage:
  threads:run [options] [--] <account_name>

Arguments:
  account_name                                     Account name

Options:
      --no-cache                                   Whether to use cache or not
  -m, --minimum-thread-size[=MINIMUM-THREAD-SIZE]  Minimum number of posts to consider it a "thread". [default: 3]

# ...

# Example:

> php run.php janedoe -m 4
> php run.php johndoe
```

After that, check the `cache/output/` directory to find all your posts' HTML files!
