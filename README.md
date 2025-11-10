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
   composer install
   bun install && bun build
    ```
3. Copy the env file and set your OpenAI API, Resend, and Stripe keys:

TODO: what documentation should be added here? Does Sentry need to be included?
   ```bash
   cp .env.example .env
   ```
4. Generate an app key:
    ```bash
    php artisan key:generate
    ```
5. Run database migrations:
    ```bash
    php artisan migrate
    ```
6. Run the development server:
    ```bash
    composer run dev
    ```
## Stripe
This project uses Stripe for processing payments and handling premium features. For local testing, run the DB seed to create a dummy user with premium access.

TODO: look into using Stripe's testing/CLI tools to simulate payments.
