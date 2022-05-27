1. Git clone repo
2. Enable as a website (preferably local)
3. Run `composer install`. Install [composer](https://getcomposer.org/download/) if you don't have one
4. Make copy of each file in `config` w/o `.example` extension in the end. Define `{end side}` and SQL Views connections
5. Go to the webpage. If you defined `{end side}` connection right you will see your Lead count
6. Available options:
- Set AB for existing leads
- Transfer data from Views to `{end side}` via PDO (default) or CSV files (commented in code)