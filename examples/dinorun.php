#!/usr/bin/env php
<?php

require_once __DIR__.'/vendor/autoload.php';

use Laravel\Prompts\Terminal;

/**
 * DinoRun Game - Terminal Chrome Dinosaur game clone
 *
 * Controls:
 * - Space/Up Arrow: Jump
 * - q: Quit the game
 */
class DinoRun
{
    // Game state
    private bool $gameOver = false;

    private bool $jumping = false;

    private int $jumpHeight = 0;

    private int $score = 0;

    private float $gameSpeed = 2.3;

    private array $obstacles = [];

    private bool $running = true;

    private int $nextObstacleAt = 18;

    private int $minObstacleInterval = 40;

    private int $maxObstacleInterval = 120;

    // Game objects
    private int $dinoY = 0;        // Dinosaur vertical position (0 = ground)

    private int $jumpStep = 0;     // Step in the jump animation

    private int $maxJumpHeight = 10; // Maximum jump height

    private int $gravity = 1;      // Rate of falling

    // Game dimensions
    private int $width = 80;

    private int $height = 20;

    private int $groundY = 15;      // Ground level position

    // Game sprites
    private array $dinoSprites = [];

    private array $obstacleSprites = [];

    private string $groundChar = '_';

    // Animation frames
    private int $frame = 0;

    private float $lastFrameTime = 0;

    private float $frameInterval = 0.1; // Seconds between frames

    // Background elements
    private array $clouds = [];

    private array $groundElements = [];

    // Terminal
    private Terminal $terminal;

    private $stdinMode = null;

    public function __construct()
    {
        $this->terminal = new Terminal;
        $this->height = min($this->terminal->lines() - 2, 50);
        $this->width = min($this->terminal->cols() - 1, 120);
        $this->groundY = $this->height - 5;
        $this->initSprites();
        $this->initBackground();
    }

    /**
     * Initialize sprites for the game
     */
    private function initSprites(): void
    {
        // Running dinosaur (2 frames of animation)
        $animationOne = [
            '          🎩          ',
            '       ████████      ',
            '      ███▄███████    ',
            '      ███████████    ',
            '      ██████         ',
            '      █████████      ',
            '█     ███████        ',
            '██    ████████████   ',
            '███  ██████████  █   ',
            '███████████████      ',
            ' █████████████       ',
            '    ████████         ',
            '     ███  ██         ',
            '     ██    ██        ',
        ];
        $animationTwo = [
            '          🎩          ',
            '       ████████      ',
            '      ███▄███████    ',
            '      ███████████    ',
            '      ██████         ',
            '      █████████      ',
            ' █    ███████        ',
            '██    ████████████   ',
            '███  ██████████  █   ',
            '███████████████      ',
            ' █████████████       ',
            '    ████████         ',
            '     ███  ██         ',
            '     ██    ██        ',
        ];
        $this->dinoSprites = [
            $animationOne,
            $animationOne,
            $animationOne,
            $animationTwo,
            $animationTwo,
            $animationTwo,
        ];

        // Obstacle sprites
        $this->obstacleSprites = [
            'smallCactus' => [
                ' █ ',
                '███',
                ' █ ',
                ' █ ',
            ],
            'tallCactus' => [
                ' █ ',
                '███',
                ' █ ',
                ' █ ',
                ' █ ',
                ' █ ',
            ],
            'cactusGroup' => [
                ' █  █  █ ',
                '███ ██ ███',
                ' █  █  █ ',
                ' █  █  █ ',
            ],
            'rock' => [
                '  ▄▄▄',
                ' ▄███▄',
                '▄█████▄',
            ],
            'rabbit' => [
                '    //',
                "  <' )",
                ' jj\\',
                '_((/*',
            ],
        ];
    }

    /**
     * Initialize background elements
     */
    private function initBackground(): void
    {
        // Initialize clouds
        for ($i = 0; $i < 5; $i++) {
            $this->clouds[] = [
                'x' => rand(0, $this->width),
                'y' => rand(1, 8),
                'shape' => ['☁️', '🌥️'][rand(0, 1)],
            ];
        }

        // Initialize background birds (different from obstacle birds)
        for ($i = 0; $i < 2; $i++) {
            $birdShapes = [
                '~v~',  // Simple bird
                '>^<',  // Another bird shape
                '^v^',  // Bird with wings up
                '.v.',  // Tiny bird
                '~o~',   // Bird with round body
            ];

            $this->clouds[] = [
                'x' => rand(0, $this->width),
                'y' => rand(2, 11),
                'shape' => $birdShapes[rand(0, count($birdShapes) - 1)],
                'isBird' => true,
                'direction' => rand(0, 1) ? 1 : -1,  // Birds can move left or right
                'speed' => (rand(10, 20) / 10),       // Varying speeds
            ];
        }

        // Initialize ground elements (small rocks, etc)
        for ($i = 0; $i < 7; $i++) {
            $this->groundElements[] = [
                'x' => rand(0, $this->width),
                'y' => $this->groundY - 1,
                'shape' => ['.', '·', '°'][rand(0, 2)],
            ];
        }

        // Initialize ground elements (small rocks, etc)
        for ($i = 0; $i < 3; $i++) {
            $this->groundElements[] = [
                'x' => rand(0, $this->width),
                'y' => $this->groundY + rand(1, 2),
                'shape' => ['.', '·', '°', '^'][rand(0, 3)],
            ];
        }
    }

    /**
     * Set up terminal for non-blocking input
     */
    private function setupTerminal(): void
    {
        system('stty -echo');  // Turn off terminal echo

        // Save terminal settings
        $this->stdinMode = shell_exec('stty -g');

        // Configure terminal for character-at-a-time input without requiring Enter key
        system('stty -icanon -echo');

        // Clear screen
        $this->clearScreen();

        // Hide cursor
        echo "\033[?25l";
    }

    /**
     * Restore terminal settings
     */
    private function restoreTerminal(): void
    {
        if ($this->stdinMode !== null) {
            system('stty '.$this->stdinMode);
        }

        // Show cursor
        echo "\033[?25h";

        // Clear screen
        $this->clearScreen();
    }

    /**
     * Clear the screen
     */
    private function clearScreen(): void
    {
        echo "\033[H\033[J";
    }

    /**
     * Check for user input without blocking
     */
    private function handleInput(): void
    {
        // Check if input is available
        $read = [STDIN];
        $write = [];
        $except = [];

        if (stream_select($read, $write, $except, 0, 0)) {
            $char = fread(STDIN, 1);

            // Handle key presses
            if ($char === "\033") {
                // Could be an arrow key
                $char .= fread(STDIN, 2);
                if ($char === "\033[A") {
                    // Up arrow
                    $this->jump();
                }
            } elseif ($char === ' ') {
                // Space bar
                $this->jump();
            } elseif ($char === 'q') {
                // Quit
                $this->running = false;
            } elseif ($char === 'r' && $this->gameOver) {
                // Restart
                $this->restart();
            }
        }
    }

    /**
     * Handle a jump action
     */
    private function jump(): void
    {
        if (! $this->jumping && $this->dinoY === 0) {
            $this->jumping = true;
            $this->jumpStep = 0;
        }
    }

    /**
     * Restart the game after game over
     */
    private function restart(): void
    {
        $this->gameOver = false;
        $this->jumping = false;
        $this->jumpStep = 0;
        $this->dinoY = 0;
        $this->score = 0;
        $this->gameSpeed = 2.3;
        $this->obstacles = [];
        $this->frame = 0;
        // $this->nextObstacleAt = 30;
        // $this->minObstacleInterval = 80;
        // $this->maxObstacleInterval = 220;
    }

    /**
     * Update the game state for one frame
     */
    private function update(): void
    {
        if ($this->gameOver) {
            return;
        }

        // Increase the frame counter
        $this->frame++;

        // Move obstacles
        $this->moveObstacles();

        // Update background elements
        $this->updateBackground();

        // Increase score
        $this->score++;

        // Manage game speed - increase gradually
        if ($this->score % 25 === 0) {
            $this->gameSpeed += 0.1;
            // Also decrease the obstacle interval as the game speeds up
            // As we meet each obstacle, schedule in the next?
            if ($this->nextObstacleAt === $this->score) {
                $this->minObstacleInterval = min(20, $this->minObstacleInterval - 5);
                $this->maxObstacleInterval = min(60, $this->maxObstacleInterval - 5);
            }
        }

        // Handle jump physics
        if ($this->jumping) {
            // Going up
            if ($this->jumpStep < $this->maxJumpHeight) {
                $this->dinoY += 2;
                $this->jumpStep += 2;
            } else {
                // Start falling
                $this->dinoY -= $this->gravity;
                if ($this->dinoY <= 0) {
                    $this->dinoY = 0;
                    $this->jumping = false;
                    $this->jumpStep = 0;
                }
            }
        }

        // Check for collisions
        $this->checkCollisions();

        // Generate obstacles at regular intervals
        $this->manageObstacles();
    }

    /**
     * Manage obstacle generation
     */
    private function manageObstacles(): void
    {
        // Debug information - uncomment to see status
        // echo "Score: {$this->score}, Next obstacle at: {$this->nextObstacleAt}, Obstacles: " . count($this->obstacles) . "\n";

        // Generate initial obstacle distance if none set
        if ($this->nextObstacleAt === 0) {
            $this->nextObstacleAt = $this->score + $this->getRandomObstacleInterval();
        }

        // If we've reached the point to add a new obstacle
        if ($this->score >= $this->nextObstacleAt) {
            // Generate an obstacle
            $this->generateObstacle();

            // Set the next obstacle point
            $this->nextObstacleAt = $this->score + $this->getRandomObstacleInterval();
        }
    }

    /**
     * Get a random interval for the next obstacle based on game speed
     */
    private function getRandomObstacleInterval(): int
    {
        // Adjust interval based on game speed - faster game = shorter intervals
        $speedFactor = max(0.5, 1.0 - ($this->gameSpeed - 1.0) * 0.2);

        $min = (int) ($this->minObstacleInterval * $speedFactor);
        $max = (int) ($this->maxObstacleInterval * $speedFactor);

        return rand($min, $max);
    }

    /**
     * Generate a new obstacle
     */
    private function generateObstacle(): void
    {
        // Define obstacle types with their probabilities
        $obstacleTypes = [
            'smallCactus' => 25,  // 35% chance
            'tallCactus' => 15,   // 20% chance
            'cactusGroup' => 15,  // 15% chance
            'rock' => 25,         // 15% chance
            'rabbit' => 20,        // 15% chance (replaced bird)
        ];

        // Calculate total weight
        $totalWeight = array_sum($obstacleTypes);

        // Generate a random number between 1 and total weight
        $rand = rand(1, $totalWeight);

        // Select an obstacle type based on the weights
        $selectedType = '';
        $currentWeight = 0;

        foreach ($obstacleTypes as $type => $weight) {
            $currentWeight += $weight;
            if ($rand <= $currentWeight) {
                $selectedType = $type;
                break;
            }
        }

        // Add the obstacle at the right edge of the screen
        $this->obstacles[] = [
            'type' => $selectedType,
            'x' => $this->width,
            'yOffset' => 0,
        ];

        // Debug message
        // echo "Generated obstacle type: {$selectedType} at position: {$this->width}\n";
    }

    /**
     * Update background elements (clouds, birds, ground details)
     */
    private function updateBackground(): void
    {
        // Update clouds and birds
        foreach ($this->clouds as $key => $cloud) {
            if (isset($cloud['isBird']) && $cloud['isBird']) {
                // Birds move in their own direction at their own speed
                $this->clouds[$key]['x'] += $cloud['direction'] * $cloud['speed'];

                // Birds occasionally change direction
                if (rand(0, 100) < 5) { // 5% chance to change direction
                    $this->clouds[$key]['direction'] *= -1;
                }

                // Birds occasionally change height slightly
                if (rand(0, 100) < 10) { // 10% chance to change height
                    $this->clouds[$key]['y'] += rand(0, 1) ? 1 : -1;
                    // Keep birds in reasonable height range
                    $this->clouds[$key]['y'] = max(1, min(8, $this->clouds[$key]['y']));
                }
            } else {
                // Regular clouds just move left slowly
                $this->clouds[$key]['x'] -= 0.5;
            }

            // Wrap clouds and birds around screen
            if ($this->clouds[$key]['x'] < -5) {
                $this->clouds[$key]['x'] = $this->width;
                $this->clouds[$key]['y'] = rand(1, 5);
            } elseif ($this->clouds[$key]['x'] > $this->width + 5) {
                $this->clouds[$key]['x'] = -5;
                $this->clouds[$key]['y'] = rand(1, 5);
            }
        }

        // Update ground elements
        foreach ($this->groundElements as $key => $element) {
            // Move ground elements quickly to the left (creates illusion of running)
            $this->groundElements[$key]['x'] -= (int) ceil($this->gameSpeed);

            // Wrap around screen
            if ($this->groundElements[$key]['x'] < 0) {
                $this->groundElements[$key]['x'] = $this->width - 1;
            }
        }
    }

    /**
     * Move obstacles and remove ones that are off-screen
     */
    private function moveObstacles(): void
    {
        foreach ($this->obstacles as $key => $obstacle) {
            // Move obstacle based on game speed
            $this->obstacles[$key]['x'] -= (int) ceil($this->gameSpeed);

            // Remove obstacles that have moved off the screen
            if ($this->obstacles[$key]['x'] < -5) {
                unset($this->obstacles[$key]);
            }
        }

        // Re-index array after removing elements
        $this->obstacles = array_values($this->obstacles);
    }

    /**
     * Check for collisions between dinosaur and obstacles
     */
    private function checkCollisions(): void
    {
        // Simplified collision detection
        $dinoX = 10; // Dinosaur fixed X position
        $dinoHeight = count($this->dinoSprites[0]);
        $dinoWidth = mb_strlen($this->dinoSprites[0][0]);

        // Adjust dinosaur hitbox to be smaller than its visual appearance
        // This creates a more forgiving collision detection
        $hitboxReductionX = 2; // Reduce width of hitbox
        $hitboxReductionY = 1; // Reduce height of hitbox

        foreach ($this->obstacles as $obstacle) {
            $obstacleX = $obstacle['x'];
            $obstacleType = $obstacle['type'];
            $obstacleHeight = count($this->obstacleSprites[$obstacleType]);
            $obstacleWidth = mb_strlen($this->obstacleSprites[$obstacleType][0]);

            // Create hitboxes (smaller than the actual sprites)
            $dinoBox = [
                'x1' => $dinoX + $hitboxReductionX,
                'y1' => $this->groundY - $dinoHeight - $this->dinoY + $hitboxReductionY,
                'x2' => $dinoX + $dinoWidth - $hitboxReductionX,
                'y2' => $this->groundY - $this->dinoY - $hitboxReductionY,
            ];

            $obstacleBox = [
                'x1' => $obstacleX + 1, // Small adjustment to obstacle hitbox
                'y1' => $this->groundY - $obstacleHeight + 1,
                'x2' => $obstacleX + $obstacleWidth - 1,
                'y2' => $this->groundY - 1,
            ];

            // Visual debugging of hitboxes (uncomment to see)
            // $this->drawHitbox($dinoBox, 'D');
            // $this->drawHitbox($obstacleBox, 'O');

            // Check for overlap
            if (
                $dinoBox['x1'] < $obstacleBox['x2'] &&
                $dinoBox['x2'] > $obstacleBox['x1'] &&
                $dinoBox['y1'] < $obstacleBox['y2'] &&
                $dinoBox['y2'] > $obstacleBox['y1']
            ) {
                $this->gameOver = true;
                break;
            }
        }
    }

    /**
     * Helper method for debugging hitboxes
     * Uncomment calls to this in checkCollisions() to visualize hitboxes
     */
    private function drawHitbox($box, $char = '#'): void
    {
        echo "Hitbox: ({$box['x1']},{$box['y1']}) to ({$box['x2']},{$box['y2']})\n";

        // Draw horizontal lines
        for ($x = $box['x1']; $x <= $box['x2']; $x++) {
            // Top line
            echo "\033[".$box['y1'].';'.$x.'H'.$char;
            // Bottom line
            echo "\033[".$box['y2'].';'.$x.'H'.$char;
        }

        // Draw vertical lines
        for ($y = $box['y1']; $y <= $box['y2']; $y++) {
            // Left line
            echo "\033[".$y.';'.$box['x1'].'H'.$char;
            // Right line
            echo "\033[".$y.';'.$box['x2'].'H'.$char;
        }
    }

    /**
     * Render the game state
     */
    private function render(): void
    {
        // Only update the score and speed information using ANSI escape sequences
        // instead of clearing the entire screen
        if ($this->frame > 1) {
            // Position cursor at where the score text is (line 2, column 2)
            $scoreText = "Score: {$this->score} | Speed: ".number_format($this->gameSpeed, 1);
            echo "\033[2;2H".$scoreText."\033[0K"; // Move to position 2,2 and clear to end of line

            // Track which lines need to be redrawn due to moving elements
            $linesToRedraw = [];

            // Also track positions where obstacles were in previous frame to ensure cleanup
            static $previousObstaclePositions = [];
            $currentObstaclePositions = [];

            // Handle obstacles - mark lines that need redrawing
            foreach ($this->obstacles as $obstacle) {
                $obstacleType = $obstacle['type'];
                $obstacleX = $obstacle['x'];
                $yOffset = $obstacle['yOffset'] ?? 0;
                $obstacleHeight = count($this->obstacleSprites[$obstacleType]);

                // Mark lines where obstacles are
                for ($y = 0; $y < $obstacleHeight; $y++) {
                    $sceneY = $this->groundY - $obstacleHeight + $y - $yOffset;
                    if ($sceneY >= 0 && $sceneY < $this->height) {
                        $linesToRedraw[$sceneY] = true;

                        // Record current obstacle positions for next frame cleanup
                        $lineWidth = mb_strlen($this->obstacleSprites[$obstacleType][$y]);
                        for ($x = 0; $x < $lineWidth; $x++) {
                            $posX = $obstacleX + $x;
                            if ($posX >= 0 && $posX < $this->width) {
                                $currentObstaclePositions[$sceneY][$posX] = true;
                            }
                        }
                    }
                }
            }

            // Add lines where obstacles were in previous frame for cleanup
            foreach ($previousObstaclePositions as $y => $xPositions) {
                $linesToRedraw[$y] = true;
            }

            // Handle ground elements - mark lines that need redrawing
            foreach ($this->groundElements as $element) {
                $elementY = $element['y'];
                if ($elementY >= 0 && $elementY < $this->height) {
                    $linesToRedraw[$elementY] = true;
                }
            }

            // Also add the ground line
            $linesToRedraw[$this->groundY] = true;

            // Always include the dinosaur's feet lines in redraw
            $dinoHeight = count($this->dinoSprites[0]);
            $dinoY = $this->groundY - $dinoHeight - $this->dinoY;

            // Add the bottom 3 lines of the dinosaur (feet and legs)
            for ($i = $dinoHeight - 3; $i < $dinoHeight; $i++) {
                $feetY = $dinoY + $i;
                if ($feetY >= 0 && $feetY < $this->height) {
                    $linesToRedraw[$feetY] = true;
                }
            }

            // Clear and redraw only the lines that have moving elements
            foreach ($linesToRedraw as $y => $true) {
                // Position cursor at start of line and clear the entire line
                echo "\033[".($y + 1).";1H\033[2K";

                // Redraw ground if this is the ground line
                if ($y === $this->groundY) {
                    echo "\033[".($this->groundY + 1).';1H'.str_repeat($this->groundChar, $this->width);
                }

                // Redraw ground elements on this line
                foreach ($this->groundElements as $element) {
                    if ($element['y'] === $y) {
                        $elementX = $element['x'];
                        if ($elementX >= 0 && $elementX < $this->width) {
                            echo "\033[".($y + 1).';'.($elementX + 1).'H'.$element['shape'];
                        }
                    }
                }

                // Redraw obstacles on this line
                foreach ($this->obstacles as $obstacle) {
                    $obstacleType = $obstacle['type'];
                    $obstacleX = $obstacle['x'];
                    $yOffset = $obstacle['yOffset'] ?? 0;
                    $obstacleHeight = count($this->obstacleSprites[$obstacleType]);

                    for ($spriteY = 0; $spriteY < $obstacleHeight; $spriteY++) {
                        $sceneY = $this->groundY - $obstacleHeight + $spriteY;

                        if ($sceneY === $y) {
                            $line = $this->obstacleSprites[$obstacleType][$spriteY];
                            $lineLength = mb_strlen($line);

                            for ($x = 0; $x < $lineLength; $x++) {
                                $char = mb_substr($line, $x, 1);
                                $sceneX = $obstacleX + $x;

                                if ($char !== ' ' && $sceneX >= 0 && $sceneX < $this->width) {
                                    // Add appropriate colors based on obstacle type
                                    if (stripos($obstacleType, 'cactus') !== false) {
                                        echo "\033[".($sceneY + 1).';'.($sceneX + 1)."H\033[32m".$char."\033[0m";
                                    } elseif ($obstacleType === 'rabbit') {
                                        echo "\033[".($sceneY + 1).';'.($sceneX + 1)."H\033[33m".$char."\033[0m";
                                    } elseif ($obstacleType === 'rock') {
                                        echo "\033[".($sceneY + 1).';'.($sceneX + 1)."H\033[90m".$char."\033[0m";
                                    } else {
                                        echo "\033[".($sceneY + 1).';'.($sceneX + 1).'H'.$char;
                                    }
                                }
                            }
                        }
                    }
                }

                // Redraw dinosaur feet if this is one of the feet lines
                $dinoX = 10;
                $currentFrameIndex = $this->frame % 6;
                $dinoSprite = $this->dinoSprites[$currentFrameIndex];

                // Check if this line is part of the dinosaur
                $dinoSpriteY = $y - $dinoY;
                if ($dinoSpriteY >= 0 && $dinoSpriteY < count($dinoSprite)) {
                    $line = $dinoSprite[$dinoSpriteY];
                    if ($this->gameOver) {
                        echo "\033[".($y + 1).';'.($dinoX + 1)."H\033[31m".$line."\033[0m";
                    } else {
                        echo "\033[".($y + 1).';'.($dinoX + 1).'H'.$line;
                    }
                }
            }

            // Store current obstacle positions for next frame
            $previousObstaclePositions = $currentObstaclePositions;

            // Update only the dinosaur position and animation for parts not already redrawn
            // We still want to update dinosaur position even when game over to ensure it's fully drawn in red

            $dinoX = 10;
            $dinoY = $this->groundY - count($this->dinoSprites[0]) - $this->dinoY;
            static $lastDinoY = null;

            if ($lastDinoY === null) {
                $lastDinoY = $dinoY;
            }

            $currentFrameIndex = $this->frame % 6;
            $dinoSprite = $this->dinoSprites[$currentFrameIndex];

            // If dinosaur height changed (jumping), we need to properly redraw the entire dinosaur
            // Also redraw entirely if game over to ensure the color change is applied to the whole dinosaur
            if ($lastDinoY !== $dinoY || $this->gameOver) {
                // Clear the entire previous dinosaur position
                for ($y = 0; $y < count($dinoSprite); $y++) {
                    $clearY = $lastDinoY + $y;
                    if ($clearY >= 0 && $clearY < $this->height) {
                        echo "\033[".($clearY + 1).';'.($dinoX + 1).'H'.str_repeat(' ', 21);
                    }
                }

                // Draw the entire dinosaur at the new position
                for ($y = 0; $y < count($dinoSprite); $y++) {
                    $drawY = $dinoY + $y;
                    if ($drawY >= 0 && $drawY < $this->height) {
                        $line = $dinoSprite[$y];
                        if ($this->gameOver) {
                            echo "\033[".($drawY + 1).';'.($dinoX + 1)."H\033[31m".$line."\033[0m";
                        } else {
                            echo "\033[".($drawY + 1).';'.($dinoX + 1).'H'.$line;
                        }
                    }
                }
            } else {
                // Just update the tail animation when not jumping
                // For the running animation, we only need to update line 8 (index 7) of the dinosaur
                // which contains the tail that moves between animation frames
                $isFirstAnimation = $currentFrameIndex < 3; // First 3 frames use animationOne

                // Calculate the position for the tail character
                $tailY = $dinoY + 6; // Line 8 of the sprite (zero-indexed)

                // Only update tail animation if it's visible on screen
                if ($tailY >= 0 && $tailY < $this->height) {
                    // Position 1 for animationOne, position 2 for animationTwo
                    $tailX1 = $dinoX + 0; // First position (animation frame 1-3)
                    $tailX2 = $dinoX + 1; // Second position (animation frame 4-6)

                    // Clear both potential tail positions
                    echo "\033[".($tailY + 1).';'.($tailX1 + 1).'H '; // +1 because ANSI is 1-indexed
                    echo "\033[".($tailY + 1).';'.($tailX2 + 1).'H ';

                    // Draw tail at correct position
                    $tailX = $isFirstAnimation ? $tailX1 : $tailX2;
                    if ($this->gameOver) {
                        echo "\033[".($tailY + 1).';'.($tailX + 1)."H\033[31m█\033[0m";
                    } else {
                        echo "\033[".($tailY + 1).';'.($tailX + 1).'H█';
                    }
                }
            }

            $lastDinoY = $dinoY;

            // Return early - don't redraw the entire screen
            return;
        }

        // For the first frame, clear screen and draw everything
        $this->clearScreen();

        // Create the scene matrix
        $scene = array_fill(0, $this->height, array_fill(0, $this->width, ' '));

        // Draw background elements
        $this->renderBackground($scene);

        // Draw ground
        for ($x = 0; $x < $this->width; $x++) {
            $scene[$this->groundY][$x] = $this->groundChar;
        }

        // Draw obstacles
        foreach ($this->obstacles as $obstacle) {
            $obstacleType = $obstacle['type'];
            $obstacleX = $obstacle['x'];
            $yOffset = $obstacle['yOffset'] ?? 0;

            for ($y = 0; $y < count($this->obstacleSprites[$obstacleType]); $y++) {
                $line = $this->obstacleSprites[$obstacleType][$y];
                $lineLength = mb_strlen($line);

                for ($x = 0; $x < $lineLength; $x++) {
                    $char = mb_substr($line, $x, 1);
                    $sceneX = $obstacleX + $x;
                    $sceneY = ($this->groundY - count($this->obstacleSprites[$obstacleType])) + $y - $yOffset;

                    // Check if position is within bounds
                    if ($sceneX >= 0 && $sceneX < $this->width) {
                        // Add green color for cactus types
                        if (stripos($obstacleType, 'cactus') !== false && $char !== ' ') {
                            $scene[$sceneY][$sceneX] = "\033[32m".$char."\033[0m";
                        }
                        // Add yellow color for rabbits
                        elseif ($obstacleType === 'rabbit' && $char !== ' ') {
                            $scene[$sceneY][$sceneX] = "\033[33m".$char."\033[0m";
                        }
                        // Add gray color for rocks
                        elseif ($obstacleType === 'rock' && $char !== ' ') {
                            $scene[$sceneY][$sceneX] = "\033[90m".$char."\033[0m";
                        } else {
                            $scene[$sceneY][$sceneX] = $char;
                        }
                    }
                }
            }
        }

        // Draw dinosaur
        $dinoSprite = $this->dinoSprites[$this->frame % 6];
        $dinoX = 10;
        $dinoY = $this->groundY - count($this->dinoSprites[0]) - $this->dinoY;

        for ($y = 0; $y < count($dinoSprite); $y++) {
            $line = $dinoSprite[$y];
            $lineLength = mb_strlen($line);

            for ($x = 0; $x < $lineLength; $x++) {
                $char = mb_substr($line, $x, 1);

                // Only draw non-space characters
                if ($char !== ' ' && $dinoY + $y >= 0 && $dinoY + $y < $this->height) {
                    // Apply red color when game over, otherwise normal color
                    if ($this->gameOver && $char !== ' ') {
                        $scene[$dinoY + $y][$dinoX + $x] = "\033[31m".$char."\033[0m";
                    } else {
                        $scene[$dinoY + $y][$dinoX + $x] = $char;
                    }
                }
            }
        }

        // Display score
        $scoreText = "Score: {$this->score} | Speed: ".number_format($this->gameSpeed, 1);
        $this->writeTextToScene($scene, $scoreText, 2, 1);

        // Display controls
        $controlsText = 'Controls: [SPACE/UP] Jump | [q] Quit'.($this->gameOver ? ' | [r] Restart' : '');
        $this->writeTextToScene($scene, $controlsText, 2, $this->height - 2);

        // Render the scene
        $output = '';
        for ($y = 0; $y < $this->height; $y++) {
            $output .= implode('', $scene[$y])."\n";
        }

        echo $output;
    }

    /**
     * Helper method to write text to the scene
     */
    private function writeTextToScene(&$scene, $text, $x, $y): void
    {
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            if ($x + $i < $this->width && $y < $this->height) {
                $scene[$y][$x + $i] = mb_substr($text, $i, 1);
            }
        }
    }

    /**
     * Helper method to render background elements to the scene
     */
    private function renderBackground(&$scene): void
    {
        // Draw clouds and birds
        foreach ($this->clouds as $cloud) {
            $cloudX = (int) $cloud['x'];
            $cloudY = $cloud['y'];
            $cloudShape = $cloud['shape'];

            // Different rendering based on if it's a bird or cloud
            if (isset($cloud['isBird']) && $cloud['isBird']) {
                // For birds, render the entire shape (which is a string like "~v~")
                $birdShape = $cloudShape;
                $length = mb_strlen($birdShape);

                for ($i = 0; $i < $length; $i++) {
                    $char = mb_substr($birdShape, $i, 1);
                    $x = $cloudX + $i;

                    // Check if position is within bounds
                    if ($x >= 0 && $x < $this->width && $cloudY >= 0 && $cloudY < $this->height) {
                        $scene[$cloudY][$x] = $char;
                    }
                }
            } else {
                // For clouds, just place a single character
                // Check if position is within bounds
                if ($cloudX >= 0 && $cloudX < $this->width && $cloudY >= 0 && $cloudY < $this->height) {
                    $scene[$cloudY][$cloudX] = $cloudShape;
                }
            }
        }

        // Draw ground elements
        foreach ($this->groundElements as $element) {
            $elementX = $element['x'];
            $elementY = $element['y'];
            // Check if position is within bounds
            if ($elementX >= 0 && $elementX < $this->width && $elementY >= 0 && $elementY < $this->height) {
                $scene[$elementY][$elementX] = $element['shape'];
            }
        }
    }

    /**
     * Render the game over message
     */
    private function renderGameOver(): void
    {
        // Draw game over message
        $gameOverY = $this->height / 2 - 2;
        $gameOverX = $this->width / 2 - 10;

        echo "\033[".(int) $gameOverY.';'.(int) $gameOverX.'H'.'GAME OVER';
        echo "\033[".(int) ($gameOverY + 1).';'.(int) ($gameOverX - 2).'H'."Score: {$this->score}";
        echo "\033[".(int) ($gameOverY + 2).';'.(int) ($gameOverX - 7).'H'.'Press [R] to restart or [Q] to quit';
    }

    /**
     * Main game loop
     */
    public function run(): int
    {
        // Set up terminal for non-blocking input
        $this->setupTerminal();

        try {
            // Hide cursor
            echo "\033[?25l";

            // Clear screen
            $this->clearScreen();

            // Main game loop
            while ($this->running) {
                // Process input
                $this->handleInput();

                // Update game state
                $this->update();

                // Render
                $this->render();

                // If game is over, give the player a chance to restart
                if ($this->gameOver) {
                    $this->renderGameOver();
                }

                // Short sleep to avoid high CPU usage
                usleep(75000);
            }

            return $this->score;
        } finally {
            // Always restore terminal settings
            $this->restoreTerminal();

            // Show cursor
            echo "\033[?25h";

            // Clear screen and move cursor to bottom
            $this->clearScreen();
            echo "\033[".$this->height.';1H';

            // Show final score
            echo "Game over! Final score: {$this->score}\n";
        }
    }
}

// Create and run the game
$game = new DinoRun;
$finalScore = $game->run();
