# Search My Audio
***Search and Chat with Your Audio Files***

Effortlessly transcribe, search, and ask questions about textual content found in your audio files. 

## Development
1. Clone the repository:
   ```bash
   git clone git@github.com:isaacbraun/search-my-audio.git
   cd search-my-audio
    ```
2. Install and build dependencies:
   ```bash
   # Run the Flux commands to authenticate and install Flux Pro
   composer config repositories.flux-pro composer https://composer.fluxui.dev
   composer config http-basic.composer.fluxui.dev [USERNAME] [TOKEN]
   composer require livewire/flux-pro
   # Then install and build all dependencies
   composer install
   bun install && bun build
    ```
3. Copy the env file and set your OpenAI API, Resend, and Stripe keys:

TODO: what documentation should be added here? Does Sentry need to be included?
   ```bash
   cp .env.example .env
   ```
4. Run IDE Helper generation commands
    ```bash
    php artisan ide-helper:generate
    php artisan ide-helper:meta
   ```
5. Generate an app key:
    ```bash
    php artisan key:generate
    ```
6. Run database migrations:
    ```bash
    php artisan migrate
    ```
7. Run the development server:
    ```bash
    composer run dev
    ```
## Stripe
This project uses Stripe for processing payments and handling premium features. For local testing, run the DB seed to create a dummy user with premium access.

TODO: look into using Stripe's testing/CLI tools to simulate payments.
