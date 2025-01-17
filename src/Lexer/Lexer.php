<?php

namespace RegexParser\Lexer;

use RegexParser\Lexer\Exception\LexerException;

class Lexer
{
    protected StringStream $stream;

    /**
     * @var array<string, string>
     */
    protected static ?array $lexemeMap = null;

    public function __construct(StringStream $stream)
    {
        $this->stream = $stream;

        if (self::$lexemeMap === null) {
            $contents = file_get_contents(__DIR__ . '/Resource/config/tokens.json');
            if ($contents === false) {
                throw new \RuntimeException('unable to read token file');
            }
            $decoded = json_decode($contents, true, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('unable to read token file');
            }
            self::$lexemeMap = array_flip($decoded);
        }
    }

    public static function create(string $input): Lexer
    {
        return new self(new StringStream($input));
    }

    public function getStream(): StringStream
    {
        return $this->stream;
    }

    /**
     * @return Token|EscapeToken|null
     * @throws LexerException
     */
    public function nextToken()
    {
        if (($char = $this->stream->next()) === null) {
            return null;
        }

        if (
            isset(self::$lexemeMap[$char]) &&
            mb_substr(self::$lexemeMap[$char], 0, strlen('T_UNICODE')) !== 'T_UNICODE' &&
            mb_substr(self::$lexemeMap[$char], 0, strlen('T_ANY')) !== 'T_ANY'
        ) {
            return new Token(self::$lexemeMap[$char], $char);
        }

        if ($this->isInteger($char)) {
            return new Token('T_INTEGER', /*(int)*/ $char);
        }

        if ($this->isAlpha($char) || $this->isWhitespace($char)) {
            return new Token('T_CHAR', $char);
        }

        if ($char === '\\') {
            $readAt1 = $this->stream->readAt(1);
            if ($readAt1 === '\\' || $readAt1 === null) {
                $this->stream->next();

                return new Token('T_BACKSLASH', '\\');
            }

            if ($readAt1 === 'p' || $readAt1 === 'P') {
                return $this->readUnicode();
            }

            if ($readAt1 === 'X') {
                return new EscapeToken('T_UNICODE_X', 'X');
            }

            if (isset(self::$lexemeMap[$readAt1]) && mb_substr(self::$lexemeMap[$readAt1], 0, strlen('T_ANY')) === 'T_ANY') {
                return new EscapeToken(self::$lexemeMap[$readAt1], $readAt1);
            }

            if (
                isset(self::$lexemeMap[mb_strtolower($readAt1)]) &&
                mb_substr(self::$lexemeMap[mb_strtolower($readAt1)], 0, strlen('T_ANY')) === 'T_ANY'
            ) {
                return new EscapeToken(self::$lexemeMap[mb_strtolower($readAt1)], $readAt1, true);
            }

            return new Token('T_CHAR', $this->stream->next());
        }

        throw new LexerException(sprintf('Unknown token %s at %s', $char, $this->stream->cursor()));
    }

    /**
     * @return EscapeToken
     * @throws LexerException
     */
    protected function readUnicode(): EscapeToken
    {
        $isExclusionSequence = $this->stream->next() === 'P';
        $isWithBrace = $this->stream->next() === '{';

        if (!$isWithBrace) {
            $token = $this->stream->current();
        } else {
            $token = '';
            if ($this->stream->readAt(1) === '^') {
                $isExclusionSequence = true;
                $this->stream->next();
            }

            do {
                $char = $this->stream->next();

                if ($char !== '}') {
                    $token .= $char;
                }
            } while ($char && $char !== '}');

            if ($char !== '}') {
                throw new LexerException(sprintf('Unknown token %s at %s', $char, $this->stream->cursor()));
            }
        }

        if (isset(self::$lexemeMap[$token])) {
            return new EscapeToken(self::$lexemeMap[$token], $token, $isExclusionSequence);
        }

        throw new LexerException(sprintf('Unknown unicode token %s at %s', $token, $this->stream->cursor()));
    }

    protected function isWhitespace(string $char): bool
    {
        // IE treats non-breaking space as \u00A0
        return ($char === ' ' || $char === "\r" || $char === "\t" ||
                $char === "\n" || $char === "\v" || $char === "\u00A0");
    }

    protected function isInteger(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }

    protected function isAlpha(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z') ||
               ($char >= 'A' && $char <= 'Z');
    }

    protected function isNewLine(string $char): bool
    {
        return $char === "\r" || $char === "\n";
    }
}
