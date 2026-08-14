<?php

declare(strict_types=1);

namespace Aaxis\Bundle\OntologyBundle\Dwl\Language;

enum TokenType: string
{
    // Literals
    case Number = 'NUMBER';
    case String = 'STRING';
    case Boolean = 'BOOLEAN';
    case Null = 'NULL';
    case Regex = 'REGEX';
    case DateLiteral = 'DATE_LITERAL';

    // Identifiers & Keywords
    case Identifier = 'IDENTIFIER';
    case DwVersion = 'DW_VERSION';
    case Output = 'OUTPUT';
    case Input = 'INPUT';
    case Var = 'VAR';
    case Fun = 'FUN';
    case Type = 'TYPE';
    case Ns = 'NS';
    case Import = 'IMPORT';
    case From = 'FROM';
    case As = 'AS';
    case Is = 'IS';
    case If = 'IF';
    case Else = 'ELSE';
    case Unless = 'UNLESS';
    case Default = 'DEFAULT';
    case Do = 'DO';
    case Using = 'USING';
    case And = 'AND';
    case Or = 'OR';
    case Not = 'NOT';
    case Case = 'CASE';
    case Matches = 'MATCHES';
    case Match = 'MATCH';
    case True = 'TRUE';
    case False = 'FALSE';
    case NullKeyword = 'NULL_KW';
    case Private = 'PRIVATE';
    case Async = 'ASYNC';
    case Yield = 'YIELD';
    case For = 'FOR';
    case Throw = 'THROW';
    case Enum = 'ENUM';

    // Operators
    case Plus = 'PLUS';
    case Minus = 'MINUS';
    case Multiply = 'MULTIPLY';
    case Divide = 'DIVIDE';
    case Modulo = 'MODULO';
    case PlusPlus = 'PLUS_PLUS';
    case MinusMinus = 'MINUS_MINUS';
    case Greater = 'GREATER';
    case GreaterEqual = 'GREATER_EQUAL';
    case Less = 'LESS';
    case LessEqual = 'LESS_EQUAL';
    case Equal = 'EQUAL';
    case NotEqual = 'NOT_EQUAL';
    case SimilarTo = 'SIMILAR_TO';
    case Arrow = 'ARROW';
    case FatArrow = 'FAT_ARROW';
    case Tilde = 'TILDE';
    case DotDot = 'DOT_DOT';
    case HashBang = 'HASH_BANG';

    // Delimiters
    case LeftParen = 'LEFT_PAREN';
    case RightParen = 'RIGHT_PAREN';
    case LeftBracket = 'LEFT_BRACKET';
    case RightBracket = 'RIGHT_BRACKET';
    case LeftBrace = 'LEFT_BRACE';
    case RightBrace = 'RIGHT_BRACE';
    case Comma = 'COMMA';
    case Colon = 'COLON';
    case ColonColon = 'COLON_COLON';
    case Dot = 'DOT';
    case DotStar = 'DOT_STAR';
    case At = 'AT';
    case Hash = 'HASH';
    case Pipe = 'PIPE';
    case Ampersand = 'AMPERSAND';
    case Question = 'QUESTION';
    case Dollar = 'DOLLAR';
    case DollarDollar = 'DOLLAR_DOLLAR';
    case DollarDollarDollar = 'DOLLAR_DOLLAR_DOLLAR';
    case Separator = 'SEPARATOR'; // ---

    // Special
    case EOF = 'EOF';
    case Newline = 'NEWLINE';
}
