<?php
namespace HeroWO\H3M;

// Don't use JSON_PRESERVE_ZERO_FRACTION because if sprintf('%u') is used
// (PHP_INT_SIZE == 4) then large integers will be float in PHP and thus stored
// as floats in JSON which is simply unnecessary. Besides, HoMM 3 map data
// doesn't use float numbers.
const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
                   JSON_PRETTY_PRINT;

// These are null in the web context.
define(__NAMESPACE__.'\\STDERR', defined('STDERR') ? STDERR : null);
define(__NAMESPACE__.'\\STDOUT', defined('STDOUT') ? STDOUT : null);

// Used when there's no need to display stack trace and printState().
class CliError extends \Exception {}

// Low-level Stream exception (premature EOF, etc.).
class StreamError extends \Exception {}

// Collection of key/value pairs.
//
// Unlike native arrays, json_encode() always serializes Hash as an object, even
// if it's empty or if its keys are sequential.
//
// Implementing ArrayAccess allows using array operators including [] and
// foreach, making it convenient for PHP code.
//
// Note: "hash of" in description of Structure properties doesn't mean the field
// is a Hash object, it means that when serialized, that field always becomes
// "{...}".
//
// $hash = new Hash;
// echo json_encode(publicProperties($hash));   //=> []
// echo json_encode($hash);                     //=> {}
//
// $hash[] = 'first';
// echo json_encode(publicProperties($hash));   //=> ["first"]
// echo json_encode($hash);                     //=> {"0": "first"}
//
// $hash[5] = 'second';
// echo json_encode(publicProperties($hash));
// echo json_encode($hash);
//   //=> both: {"0": "first", "5": "second"}
class Hash implements \ArrayAccess, \Countable {
  protected $_nextKey = 0;

  function __construct(array $assign = []) {
    foreach ($assign as $k => $v) { $this[$k] = $v; }
  }

  function __set($name, $value) {
    if (ltrim($name, '0..9') === '' and $this->_nextKey <= $name) {
      $this->_nextKey = $name + 1;
    }
    $this->$name = $value;
  }

  #[\ReturnTypeWillChange]
  function offsetSet($offset, $value) {
    isset($offset) or $offset = $this->_nextKey;
    $this->$offset = $value;
  }

  #[\ReturnTypeWillChange]
  function offsetExists($offset) {
    return isset($this->$offset);
  }

  #[\ReturnTypeWillChange]
  function offsetUnset($offset) {
    unset($this->$offset);
  }

  #[\ReturnTypeWillChange]
  function offsetGet($offset) {
    return $this->$offset;
  }

  #[\ReturnTypeWillChange]
  function count() {
    $res = 0;
    foreach (publicProperties($this) as $value) { $res++; }
    return $res;
  }

  // Ensures own keys are non-negative numbers (inserted in any order with
  // possible gaps). Returns the largest key plus 1.
  //
  // $hash[2] = 'a';
  // $hash[0] = 'b';
  // echo count($hash), $hash->nextKey();   //=> 2, 2
  // unset($hash[2]);
  // echo count($hash), $hash->nextKey();   //=> 1, 2
  function nextKey() {
    foreach (publicProperties($this) as $key => $value) {
      if (!is_int($key) or $key < 0 or $key >= $this->_nextKey) {
        throw new \RuntimeException('Hash must have only non-negative integer keys.');
      }
    }
    return $this->_nextKey;
  }
}

// Central object holding state of the parser.
class Context {
  // Version number of the common "JSON" format produced by this script.
  // Stored in the "_version" field of the root object.
  const VERSION = 1;

  // Possible values for $partial.
  const HEADER = 0;
  const ADDITIONAL = 1;
  const TILES = 2;
  const OBJECT_KINDS = 3;
  const OBJECTS = 4;
  const EVENTS = 5;

  // Optional function handling warnings occurring during parsing. See
  // warning().
  public $warner;
  // An iconv charset identifier for decoding H3M strings.
  public $charset;
  // Set to one of the constants above to only parse/build part of the H3M file.
  // Set to PHP_INT_MAX (default) to process it from start to finish.
  public $partial = PHP_INT_MAX;
  // Well-formed H3M data must end on certain number of zero bytes. If enabled
  // when parsing, $stream pointer may be positioned not immediately after map
  // data.
  public $padding = true;

  // Set after readUncompressedH3M(). A Stream instance (input data).
  public $stream;
  // Set after readUncompressedH3M(). An H3M instance (parsed objects).
  public $h3m;

  // Used internally during readUncompressedH3M().

  // Next free sequence number for recordMeta().
  public $order;
  // Hash of 'Group.X.Y.Z'|objectID => index in $h3m->objects.
  public $objectIndex;

  // Dumps snippets of input $stream and $h3m objects parsed so far.
  //
  // Unless empty, stream always includes a final line break.
  function printState(array $options = []) {
    // 000. prop (@1234h+3)      }
    // ...                       }- outlineLines
    //
    // 012345  AA AA AA AA ....  }
    //             ...           }- motionLines (above)
    // 012345  AA AA AA AA ....  }
    //        -- Motion --        - lastMotions
    // 012345  AA AA AA AA ....  }
    //             ...           }- motionLines (below)
    // 012345  AA AA AA AA ....  }
    extract($options += [
      'stream' => STDERR,
      'outlineLines' => PHP_INT_MAX,
      'outline' => ['children' => true],    // $options for dump()
      'lastMotions' => PHP_INT_MAX,
      'motionLines' => PHP_INT_MAX,
    ], EXTR_SKIP);

    if ($outlineLines > 0 and $this->h3m) {
      $outline = explode("\n", $this->h3m->dump($outline));
      fprintf($stream, '%s', join("\n", array_slice($outline,
        -$outlineLines - 1 /*last \n*/)));
    }

    if ($lastMotions > 0 and $this->stream) {
      empty($outline) or fwrite($stream, PHP_EOL);
      $this->stream->printState($options);
    }
  }

  // Performs H3M parsing. To parse a compressed H3M (i.e. the standard H3M
  // accepted by HoMM 3) use gzopen() instead of fopen():
  //   $cx->readUncompressedH3M(new Stream(gzopen(..., 'rb')));
  //
  // Configure Context ($charset, etc.) before calling this method.
  //
  // Access $h3m and/or serialize data after calling this method.
  function readUncompressedH3M(Stream $stream) {
    $this->stream = $stream;
    $this->order = 0;
    $this->objectIndex = [];
    $this->h3m = new H3M;
    $this->h3m->_version = static::VERSION;
    $this->h3m->readUncompressedH3M($this);
    $this->partial === PHP_INT_MAX and $this->resolveH3M();
    return $this;
  }

  // Does final linkage after all data has been parsed.
  protected function resolveH3M() {
    $resolve = function ($obj, $index) use (&$resolve) {
      if ($obj instanceof Structure) {
        $obj->resolveH3M($this, $index);
        $recurse = true;
      }

      if (isset($recurse) or is_array($obj) or $obj instanceof Hash) {
        foreach ($obj as $i => $value) {
          $resolve($value, $index === true ? $i : $index);
        }
      }
    };

    foreach ($this->h3m as $prop => $value) {
      $resolve($value, $prop === 'objects' ?: null);
    }
  }

  // Compiles existing objects into H3M. Configure Context and $this->h3m before
  // calling this method. $_version is overwritten with current VERSION.
  function writeUncompressedH3M(Stream $stream) {
    $this->stream = $stream;
    $this->order = 0;
    $this->h3m->_version = static::VERSION;
    $this->h3m->writeUncompressedH3M($this);
    return $this;
  }

  // Returns a string - serialized JSON representation of the input H3M.
  // This is the "json" part in "h3m2json".
  //
  // Default $flags enable pretty-printing and disable unnecessary escaping.
  function toJSON($flags = null) {
    isset($flags) or $flags = JSON_FLAGS;
    return json_encode($this->h3m, $flags);
  }

  // Returns a string - serialize()'d binary representation of the input H3M.
  // Unlike toJSON(), this is a native PHP format so unserializing it is a
  // breeze and it preserves all the custom properties and stuff out of the box.
  function toPHP() {
    return serialize($this->h3m);
  }

  // Emits a warning to the client. To be called during parsing or writing.
  // Arguments are the same as for sprintf().
  function warning($msg, $arg_1 = null) {
    if ($this->warner) {
      if ($this->stream) {
        $msg = sprintf('%06Xh: %s', $this->stream->offset(), $msg);
      }
      call_user_func($this->warner, call_user_func_array('sprintf',
        func_get_args()), $this);
    }
  }

  // Methods for internal use by Structure-s.

  // Returns a UTF-8 representation of locale-specific $s, as read from H3M.
  function convertInputString($s) {
    $s = iconv($this->charset, 'utf-8', $s);
    if ($s === false) {
      $this->warning('failed reading string as %s', $this->charset);
    } else {
      return $s;
    }
  }

  // Returns a locale-specific representation of $s, to be written into H3M.
  function convertOutputString($s) {
    $s = iconv('utf-8', $this->charset, $s);
    if ($s === false) {
      $this->warning('failed writing string as %s', $this->charset);
    } else {
      return $s;
    }
  }

  // Returns true if format of the input H3M matches $format (which is an int if
  // not part of H3M::$formats, else a str).
  //
  //   $cx->is('SoD');
  //   $cx->is(0x89);
  function is($format) {
    return $this->h3m->format === $format;
  }

  // Returns true if the input H3M's format is non-standard (not part of
  // H3M::$formats).
  function isUnknown() {
    return is_int($this->h3m->format);
  }

  // Returns true if the input H3M's format is exactly $format (must be a str)
  // or "higher".
  //
  // This assumes formats' binary values are in order of "upness" (which is the
  // case for official versions: RoE < AB < SoD).
  //
  //   $cx->isOrUp('AB');
  function isOrUp($format) {
    return array_search($format, H3M::$formats) <=
      ($this->isUnknown() ? $this->h3m->format
        : array_search($this->h3m->format, H3M::$formats));
  }

  // Returns index in H3M->$object by an object's .h3m ID number ($key is int)
  // or coordinate of bottom right corner ($key is 'Group.X.Y.Z', e.g.
  // 'town.1.2.3').
  //
  // Returns null if not found and emits a warning. $obj is only used for
  // message.
  function resolveObject($key, object $obj = null) {
    $ref = &$this->objectIndex[$key];
    if (!isset($ref)) {
      $this->warning('%s references a non-existing object: %s',
        $obj ? get_class($obj) : 'object', $key);
    }
    return $ref;
  }

  // Returns value of the $value key in $enum, or null. If $warn is not null,
  // emits a warning if no such key exists, with the "$warn:" prefix.
  //
  // $enum = ['alias', 'another', 'yet'];
  function enum(array $enum, $value, $warn = null) {
    if (array_key_exists($value, $enum)) {
      return $enum[$value];
    } elseif ($warn !== null) {
      $this->warning("%s: unknown value: %d (0x%02X)", $warn, $value, $value);
    }
  }

  // Given an alias (string enum member) $value, returns the numeric value from
  // $enum. Like enum(), emits warning if $enum has no $value. If $value is an
  // integer, it's returned unchanged (with a warning if $enum has no alias for
  // that integer).
  function packEnum(array $enum, $value, $warn = null) {
    $keys = array_keys($enum, $value, true);
    if ($keys) {
      return $keys[0];
    } elseif (is_int($value) or is_float($value)) {
      // Number could be supplied for what is a valid enum value.
      if ($warn !== null and !array_key_exists($value, $enum)) {
        $this->warning("%s: unlisted enum value: %d (0x%02X)",
          $warn, $value, $value);
      }
      return $value;
    } elseif ($warn !== null) {
      $this->warning("%s: unknown value: %d (0x%02X)", $warn, $value, $value);
    }
  }

  // Just like packEnum() but resolves multiple $values at once, keeping the
  // keys.
  function packEnumArray(array $enum, array $values, $warn = null) {
    return array_map(function ($value) use ($enum, $warn) {
      return $this->packEnum($enum, $value, $warn);
    }, $values);
  }

  // Emits a warning if $a->$ap and $b->$bp stand for different values after
  // resolving using $enum.
  //
  // For example, 'suk' value in $a and 6 value in $b pass the test if
  // $enum = [6 => 'suk'].
  function packEnumCompare(array $enum, object $a, $ap, object $b, $bp) {
    $av = $this->packEnum($enum, $a->$ap, '$'.$ap);
    $bv = $this->packEnum($enum, $b->$bp, '$'.$bp);
    if ($av !== $bv) {
      $this->warning('$%s (%s): mismatches %s->$%s (%s)',
        $ap, $av, get_class($b), $bp, $bv);
    }
  }

  // Reads/writes and converts binary data from/to $stream according to $format.
  // Returns an array of metadata.
  //
  // You probably need to call not this method but your Structure's unpack().
  //
  // This resembles standard unpack() but is implemented from scratch to work
  // around its limitations. $format consists of 'C[?] name/...' (format and
  // name must be separated by space, repeaters are not supported). '?'
  // suppresses $pack's warning when casting null value. Result is a 0-indexed
  // array. Supported characters:
  //
  //   c    signed char
  //   C    unsigned char
  //   v    unsigned short (always 16 bit, little endian byte order)
  //   V    unsigned long (always 32 bit, little endian byte order)
  //        with a fix for PHP versions that could treat V as signed
  //   s    signed short (in HoMM 3 format)
  //   S    signed long (in HoMM 3 format)
  //        (PHP's un/pack() offer only machine byte order for signed formats)
  //   b    bool (false if C == 0, else true)
  //   Bn   bitfield n bytes long; if $pack, missing bits (keys) equal false
  //   Bkn  like Bn but has special meaning if given to Structure->unpack()
  //   z    N zero bytes, N = name
  function unpack($format, array $options = [], $pack = false) {
    $res = [];

    foreach (explode('/', $format) as $part) {
      if (!preg_match('/^(([cCbvsVSz])|Bk?(\\d+))(\\?)?\\s+(\\S+)$/', $part, $match)) {
        throw new \InvalidArgumentException("Bad unpack() \$format '$part'.");
      }

      list(, , $char, $Bn, $nullable, $name) = $match;
      $nullable = $nullable ? '/null' : '';
      $offset = $this->stream->offset();
      $silent = !empty($options[$name]['silent']);
      $value = isset($options[$name]['value']) ? $options[$name]['value'] : null;

      if ($nullable and !$pack) {
        throw new \InvalidArgumentException("unpack()'s '?' is only for \$pack.");
      }

      $number = function ($length, $min, $max, $format, $complement = 0)
          use ($pack, $char, $nullable, $name, $silent, $value) {
        if ($pack) {
          if (!is_int($value) and !is_float($value)) {
            if (!$silent and ($value !== null or !$nullable)) {
              $this->warning('$%s: int %s value %s instead of int/float'.
                $nullable, $name, $char, gettype($value));
            }
            $value = (float) $value;
          }
          if ($value < $min or $value > $max) {
            if (!$silent) {
              $this->warning('$%s: int %s value %s out of range (%d..%d)',
                $name, $char, $value, $min, $max);
            }
            $value = max($min, min($max, $value));
          }
          $value += $complement;
          $this->stream->write(pack($format, $value));
        } else {
          list(, $value) = unpack($format, $this->stream->read($length));
          $value -= $complement;
        }
        return $value;
      };

      switch ($char) {
        case 'b':
          if ($pack) {
            if (!$silent and !is_bool($value) and ($value !== null or !$nullable)) {
              $this->warning('$%s: bool value %s instead of bool'.$nullable,
                $name, gettype($value));
            }
            $this->stream->write(pack('C', $value = (bool) $value));
          } else {
            list(, $value) = unpack('C', $this->stream->read(1));
            if (!$silent and $value > 1) {
              $this->warning('$%s: bool value 0x%02X instead of 00/01',
                $name, $value);
            }
            $value = $value !== 0;
          }
          break;
        case 'c':
          $value = $number(1, -128, 127, $char);
          break;
        case 'C':
          $value = $number(1, 0, (1 << 8) - 1, $char);
          break;
        case 'v':
          $value = $number(2, 0, (1 << 16) - 1, $char);
          break;
        case 'V':
          $value = $number(4, 0, 0xFFFFFFFF /*(1<<32)-1*/, $char);
          if ($value < 0) {   // PHP_INT_SIZE == 4
            $value = (float) sprintf('%u', $value);
          }
          break;
        case 's':
          $value = $number(2, -0x8000, 0x7FFF, 'v', 0xFFFF + 1);
          break;
        case 'S':
          $value = $number(4, -0x80000000, 0x7FFFFFFF, 'V');
          // unpack() returns signed values on 32-bit PHP but on 64-bit PHP we
          // must extend the sign bit.
          //
          // echo unpack('V', hex2bin('ffffffff'))[1];
          //   //=> -1 (32-bit)
          //   //=> 4294967295 (64-bit)
          //
          // 4294967295 = 11111111111111111111111111111111b
          // | 1111111111111111111111111111111100000000000000000000000000000000b
          // = -1 (64-bit)
          if (PHP_INT_SIZE > 4 and !$pack and $value & 0x80000000 /*sign*/) {
            $value |= (int) 0xFFFFFFFF00000000;
          }
          break;
        case 'z':
          if ($pack) {
            $this->stream->write(str_repeat("\0", $name));
          } else {
            $value = $this->stream->read($name);
            if (!$silent and strspn($value, "\0") !== (int) $name) {
              $this->warning('$%s: non-zero reserved bytes: %s',
                $name, bin2hex($value));
            }
          }
          break;
        case '':
          if ($Bn !== '') {
            if ($pack) {
              if (!is_array($value)) {
                if (!$silent and ($value !== null or !$nullable)) {
                  $this->warning('$%s: bitfield value %s instead of array'.$nullable,
                    $name, gettype($value));
                }
                $value = [];
              }
              $Bn *= 8;
              $bits = '';
              for ($i = 0; $i < $Bn; $i++) {
                $ref = &$value[$i];
                if (!$silent and !is_bool($ref) and $ref !== null) {
                  $this->warning('$%s[%d]: bool value %s instead of bool/null',
                    $name, $i, gettype($ref));
                }
                $bits .= (int) (bool) $ref;
              }
              if (!$silent and array_diff_key($value, range(0, $Bn - 1))) {
                $this->warning('$%s: extraneous bitfield keys', $name);
              }
              $bits = array_map('bindec', array_map('strrev', str_split($bits, 8)));
              $this->stream->write(pack('C*', ...$bits));
            } else {
              $value = unpack('C*', $this->stream->read($Bn));
              $value = array_map(function ($c) {
                return strrev(sprintf('%08b', $c));
              }, $value);
              $value = array_map('boolval', str_split(join($value)));
            }
          }
      }

      $res[$name] = compact('value', 'offset') + [
        'length' => $this->stream->offset() - $offset,
        'format' => $match[1],
      ];
    }

    return $res;
  }

  // Reads a $charset-specific string from $stream with leading uint32 (length).
  // Returns an array of metadata.
  function readString() {
    list(, $length) = unpack('V', $this->stream->read(4));
    return [
      'value' => !$length ? null :
        $this->convertInputString($this->stream->read($length)),
      'length' => $length += 4,
      'offset' => $this->stream->offset() - $length,
    ];
  }

  // Writes a $charset-specific string to $stream with leading uint32 (length).
  // Returns an array of metadata.
  function writeString($value) {
    if (!is_string($value) and isset($value)) {
      $this->warning('string value %s instead of string/null', gettype($value));
      $value = (string) $value;
    }
    $offset = $this->stream->offset();
    $value = $this->convertOutputString($value);
    $this->stream->write(pack('V', strlen($value)).$value);
    return compact('value', 'offset') + ['length' => strlen($value) + 4];
  }
}

// A base class representing a binary input or output stream.
// The PhpStream subclass wraps around standard PHP streams (fopen(), etc.).
abstract class Stream {
  // Array of previous stream offset()-s, not counting the current one
  // ($this->offset()). Allows reconstructing parser's state changes.
  public $motions = [];

  // Tries to read $length bytes, failing if any different number was returned.
  function read($length) {
    $s = $this->tryRead($length = (int) $length);
    if (strlen($s) !== $length) {
      throw new StreamError("Unable to read $length bytes (".strlen($s)." read).");
    }
    return $s;
  }

  // Tries to write $length bytes, failing if any different number was written.
  function write($s) {
    $length = $this->tryWrite($s = (string) $s);
    if ($length !== strlen($s)) {
      throw new StreamError("Unable to write ".strlen($s)." bytes ($length written).");
    }
    return $length;
  }

  // Returns current stream position in bytes from the beginning.
  abstract function offset();
  // Tries to read $length bytes, failing on $length <= 0.
  abstract function tryRead($length);
  // Tries to write $length bytes, failing on $length <= 0.
  abstract function tryWrite($length);
}

// A wrapper around standard PHP streams (fopen(), etc.).
class PhpStream extends Stream {
  protected $stream;
  protected $compressed;
  protected $autoClose;

  function __construct($stream, $compressed = false, $autoClose = false) {
    if (!is_resource($stream)) {
      throw new \InvalidArgumentException(get_class($this)." expects a fopen() resource value (".gettype($stream)." given).");
    }

    $this->stream = $stream;
    $this->compressed = $compressed;
    $this->autoClose = $autoClose;
  }

  function __destruct() {
    try {
      // fclose() also works with gzopen()'ed resources.
      $this->autoClose and fclose($this->stream);
    } catch (\Throwable $e) {
    } catch (\Exception $e) {}
  }

  // Dumps snippets of this stream, from current offset() to some number of
  // previous $motions. See Context->printState() for $options illustration.
  //
  // Unless empty, stream always includes a final line break.
  function printState(array $options = []) {
    extract($options += [
      'stream' => STDERR,
      'lastMotions' => PHP_INT_MAX,
      // Don't use PHP_INT_MAX - fread() fails with allocation error.
      'motionLines' => 0xFFFFF,
    ], EXTR_SKIP);

    if ($this->compressed) {
      return fwrite($stream, 'Stream motions are only available for uncompressed H3M (-iH/-oH)'.PHP_EOL);
    }

    $motionLines *= 16;   // 16 = column length in hexDump()
    $old = $this->offset();

    $offsets = array_slice(array_merge($this->motions, [$this->offset()]),
      -$lastMotions);

    foreach ($offsets as $i => &$ref) {
      $rem = $ref % 16;
      $ref = [
        max($ref - $motionLines - $rem, $i ? $offsets[$i - 1][2] : 0),
        $ref,
        min($ref + $motionLines + (16 - $rem),
          isset($offsets[$i + 1]) ? $offsets[$i + 1] : 0xFFFFFF),
      ];
      unset($ref);
    }

    foreach ($offsets as $i => $offset) {
      fseek($this->stream, $offset[0]);

      if ($offset[1] - $offset[0] > 0) {
        $s = fread($this->stream, $offset[1] - $offset[0]);
        $shift = $offset[0] % 16;
        fprintf($stream, "%s\n", hexDump($s, $offset[0] - $shift, $shift));
      }

      $s = sprintf('-- %s @%06Xh --',
        !$i && count($offsets) === count($this->motions) + 1
          ? 'Starting position'
          : (isset($offsets[$i + 1]) ? 'Previous motion' : 'Final motion'),
        $offset[1]);
      fprintf($stream, "%s\n", str_pad($s, 73, ' ', STR_PAD_BOTH));

      if ($offset[2] - $offset[1] > 0) {
        $s = fread($this->stream, $offset[2] - $offset[1]);
        $shift = $offset[1] % 16;
        fprintf($stream, "%s\n", hexDump($s, $offset[1] - $shift, $shift));
      }
    }

    fseek($this->stream, $old);
  }

  function offset() {
    // For gzopen() ftell() reports offset in uncompressed stream which is what
    // we need.
    return ftell($this->stream);
  }

  function tryRead($length) {
    $this->motions[] = $this->offset();
    return fread($this->stream, $length);
  }

  function tryWrite($s) {
    $this->motions[] = $this->offset();
    return fwrite($this->stream, $s);
  }
}

// A base class representing a serializable "struct". Subclasses should be
// lightweight and directly serializable. For this reason avoid using non-public
// properties because json_encode() ignores them by default while serialize()
// doesn't. Don't store complex objects like Context either. Only implement
// parsing/writing logic here.
abstract class Structure implements \JsonSerializable {
  // Configuration of factory() candidates. See that method for details.
  //
  // Set this property on Structure only, not on subclasses.
  static $factories = [];

  // Set by factory() to Structure it has instantiated. This is used when
  // un/serializing, to determine which object to create. May be left null when
  // creating a Structure manually (such as for subsequent compilation).
  //public $_type;

  // Controls if json_encode() called later on Structure-s will include "_meta"
  // (debug metadata) or not.
  //
  // Set this property on Structure only, not on subclasses.
  static $includeMeta = false;

  // Allows resetting $_meta accumulated from previous parsing/building done on
  // the same Structure. Attempt to access $_meta results in empty array if this
  // is different from the object's $_metaEpoch.
  //
  // Set this property on Structure only, not on subclasses.
  static $metaEpoch = 0;

  // Metadata of this instance. See recordMeta() and others. Only appears in
  // produced JSONs if $includeMeta is enabled.
  //
  // Key is property name or '' (metadata related to the structure as a whole).
  //
  // Value is array with keys:
  // - offset int - bytes from stream start (uncompressed)
  // - length int - size of the value in stream (uncompressed)
  // - order int - H3M-wise counter to determine field positions in stream
  //
  // Value keys for '' key:
  // - class str - fully-qualified class name
  //
  // Value keys for non-'' key:
  // - class str - for factory objects only
  // - original - value read/written from/to stream prior to/after normalization
  // - type str - a pack() character or 'string'
  protected $_meta;

  // See $metaEpoch for the description.
  protected $_metaEpoch;

  #[\ReturnTypeWillChange]
  function jsonSerialize() {
    return self::$includeMeta
      ? publicProperties($this) + ['_meta' => $this->resetMeta()] : $this;
  }

  // Returns string dump of structure of this object and possibly its children.
  //
  // Unless empty, result always includes a final line break.
  function dump(array $options = []) {
    $options += [
      // Whether to include array members and nested objects' dumps.
      'children' => true,
      // String prepended to every line (but after $order numbers).
      'indent' => '',
    ];

    // $value must be null if !$isAssigned (i.e. has only entry in $_meta but
    // not $this->$prop).
    $formatValue = function (array $options, $isAssigned, $value = null,
        array $meta = null) use (&$formatValue) {
      $valueIndent = "       $options[indent] ";
      $options['indent'] .= '  ';
      $metaText = '';

      if ($value instanceof Hash) {
        $value = publicProperties($value);
      }

      if ($meta and isset($meta['type'])) {
        $metaText = sprintf('(%s%s)',
          ($isAssigned and $value === $meta['original']) ? ''
            // Single-line is better than var_export().
            : json_encode($meta['original']).', ',
          $meta['type']);
      }

      if (is_array($value)) {
        if ($options['children']) {
          $res = sprintf("%sarray (%d) {\n", $metaText ? "$metaText " : '',
            count($value));

          uksort($value, function ($a, $b) use ($value) {
            $am = isset($value[$a]->_meta) ? $value[$a]->resetMeta() : null;
            $bm = isset($value[$b]->_meta) ? $value[$b]->resetMeta() : null;
            $ao = isset($am['']['order']) ? $am['']['order'] : null;
            $bo = isset($bm['']['order']) ? $bm['']['order'] : null;
            if ($ao === null and $bo === null) {
              return strnatcmp($a, $b);
            } else {
              return $ao === null ? +1 : ($bo === null ? -1 : $ao - $bo);
            }
          });

          foreach ($value as $key => $value) {
            $res .= "$valueIndent  $key => ".$formatValue($options, true, $value);
          }

          return "$res$valueIndent}\n";
        } else {
          return sprintf("%sarray (%d)\n", $metaText ? "$metaText " : '',
            count($value));
        }
      } elseif (is_object($value)) {
        $class = preg_replace('/.+\\\\/', '', get_class($value));

        if ($options['children']) {
          return sprintf("%s%s {\n%s%s}\n",
            $metaText ? "$metaText " : '', $class,
            $value->dump($options), $valueIndent);
        } else {
          return sprintf("%s%s\n",
            $metaText ? "$metaText " : '', $class);
        }
      } else {
        return sprintf("%s%s\n",
          $isAssigned ? var_export($value, true) : '*unassigned*',
          $metaText ? " $metaText" : '');
      }
    };

    $lines = [];
    $this->resetMeta();
    $props = $this->_meta +
      array_fill_keys(array_keys(array_diff_key(publicProperties($this), $this->_meta)), null);
    $order = max(array_column($this->_meta, 'order') ?: [0]);

    foreach ($props as $prop => $meta) {
      if ($prop !== '') {   // dumped by parent
        // $prop can be null if it was cleared after calling factory(), as with
        // MapObject->$details.
        if ($meta and isset($meta['class']) and isset($this->$prop)) {
          $meta += $this->$prop->resetMeta()[''];
        }

        $isAssigned = property_exists($this, $prop);

        if ($meta) {
          $lineOrder = $meta['order'];
        } elseif ($isAssigned and is_array($this->$prop) and
                  isset(reset($this->$prop)->_meta) and
                  isset(reset($this->$prop)->resetMeta()['']['order'])) {
          $lineOrder = reset($this->$prop)->_meta['']['order'];
        } elseif ($isAssigned and
                  isset($this->$prop->_meta) and
                  isset($this->$prop->resetMeta()['']['order'])) {
          $lineOrder = $this->$prop->_meta['']['order'];
        } else {
          $lineOrder = ++$order;
        }

        $lines[] = [$lineOrder, count($lines), sprintf("% 6s. %s%-14s%s = %s",
          $meta ? $meta['order'] : '', $options['indent'],
          $prop,
          $meta ? sprintf(' (@%06Xh+%d)', $meta['offset'], $meta['length']) : '',
          $formatValue($options, $isAssigned, $isAssigned ? $this->$prop : null, $meta)
        )];
      }
    }

    usort($lines, function ($a, $b) {
      $i = $a[0] === $b[0];
      return $a[$i] - $b[$i];
    });

    return join(array_column($lines, 2));
  }

  // Copies properties $props and their metadata from $other into $this.
  // Logically similar to $this = array_merge($this, $other).
  //
  // Pass '' in $props to copy structure meta (recordStructureMeta()).
  function mergeFrom(Structure $other, array $props) {
    $props = array_flip($props);

    foreach ($other as $prop => $value) {
      switch ($prop) {
        case '_meta':
          $this->_meta = array_intersect_key($other->resetMeta(), $props)
            + $this->resetMeta();
          break;
        default:
          isset($props[$prop]) and $this->$prop = $value;
      }
    }

    return $this;
  }

  // Performs H3M parsing - reads data of this "struct" from $cx->stream.
  // Call this from your Structure's readH3M() to read a sub-"struct".
  //
  // A common one-liner for reading a sub-Structure is:
  //   ($this->prop = new Substruct)->readUncompressedH3M($cx);
  // Note that brackets are around the assignment, not merely around new so that
  // if readUncompressedH3M() fails, the partly read object is already assigned
  // and becomes part of printState() (dump()) output, aiding in debug, as well
  // as respecting -ep (partial parse on error).
  function readUncompressedH3M(Context $cx, array $options = []) {
    $this->recordStructureMeta($cx, ['offset' => $offset = $cx->stream->offset()]);
    $this->readH3M($cx, $options);
    $this->recordStructureMeta($cx, ['length' => $cx->stream->offset() - $offset]);
    return $this;
  }

  // Performs H3M parsing - reads data of this "struct" from $cx->stream.
  // Do not call this directly - call readUncompressedH3M() instead.
  // Do override this in subclasses - don't override readUncompressedH3M().
  abstract protected function readH3M(Context $cx, array $options);

  // Called for all Structure objects after finishing reading an H3M.
  // $object is an index in H3M->$objects for objects ($this) referenced from
  // MapObject, like ObjectDetails.
  //
  // This usually resolves object references made by coordinates or objectID.
  function resolveH3M(Context $cx, $object = null) {
    if (isset($this->objectID) and property_exists($this, 'object')) {
      $this->object = $cx->resolveObject($this->objectID, $this);
    }
  }

  // Performs writing of this "struct" data in H3M format to $cx->stream.
  // Call this from your Structure's writeH3M() to write a sub-"struct".
  function writeUncompressedH3M(Context $cx, array $options = []) {
    $this->recordStructureMeta($cx, ['offset' => $offset = $cx->stream->offset()]);
    $this->writeH3M($cx, $options);
    $this->recordStructureMeta($cx, ['length' => $cx->stream->offset() - $offset]);
    return $this;
  }

  // Performs writing of this "struct" data in H3M format to $cx->stream.
  // Do not call this directly - call writeUncompressedH3M() instead.
  // Do override this in subclasses - don't override writeUncompressedH3M().
  abstract protected function writeH3M(Context $cx, array $options);

  // Stores metadata about $prop in "_meta". Can be called multiple times per
  // $prop, each call adding/overriding new $meta keys.
  //
  // Automatically adds 'order' key, if it isn't already set.
  function recordMeta(Context $cx, $prop, array $meta) {
    $ref = &$this->resetMeta()[$prop];
    $ref = $meta + ((array) $ref) + ['order' => $cx->order++];
  }

  // Clears own $_meta if a new parse/build cycle has started.
  protected function &resetMeta() {
    if ($this->_metaEpoch !== self::$metaEpoch) {
      $this->_metaEpoch = self::$metaEpoch;
      $this->_meta = [];
    }
    return $this->_meta;
  }

  // Stores metadata about this overall structure in "_meta". Can be called
  // multiple times.
  //
  // Automatically adds 'class' key, if it isn't already set.
  function recordStructureMeta(Context $cx, array $meta) {
    $this->recordMeta($cx, '', $meta + ['class' => get_class($this)]);
  }

  // Reads and converts binary data from $cx->stream according to $format.
  // Returns an array of read values.
  //
  // See Context->unpack() for $format specifiers.
  //
  // In addition to calling Context->unpack(), this method sets values as
  // properties on $this (unless 'assign' option is false), calls recordMeta()
  // and maps read numbers according to the static "{$name}s" on $this (except
  // for 'z').
  //
  // For example, 'C foo' can be used together with:
  //   static $foos = [6 => 'suk', 9 => 'ebe'];
  // If 'foo' is read as 6, it's as if it were a string 'suk'.
  // If 'foo' is being pack()'ed and value is 'suk' (or 6), it's written as 6.
  //
  // For 'Bn' specifier mapping occurs on bit positions. Normally, if 'Bn bit'
  // is read as 0b00000101 (05) then $this->bit is set to:
  //   [0 => true, 1 => false, 2 => true, 3 => false, 4 => ...]
  // ...but changes to ['gu' => true, 'rig' => false, 'ula' => true] given:
  //   static $bits = ['gu', 'rig', 'ula'];
  // In this case result is padded to count($map) and trailing false members
  // with index > count($map) are removed.
  // If pack()'ing, missing members are assumed to be false by Context->unpack().
  //
  // 'Bkn' specifier is the same as 'Bn' but result becomes array of keys whose
  // values equal to true.
  //
  // If the map property exists but doesn't contain read members, a warning
  // occurs unless $options[$name]['silent'] is true.
  //
  // A string map property indicates a "redirect" to another class' property:
  //   static $bits = 'NS\\My\\Class::$foos';
  //
  // If $options[$name]['map'] is set, its members are added to the property's.
  function unpack(Context $cx, $format, $options = []) {
    is_bool($options) and $options = ['assign' => $options];
    $options += ['assign' => true];
    $res = [];

    foreach ($cx->unpack($format, $options) as $prop => $info) {
      if ($info['format'] === 'z') {
        continue;
      }

      $value = $info['value'];
      $enum = $this->enum($prop, $options);

      if (isset($enum)) {
        $silent = !empty($options[$prop]['silent']);

        if ($info['format'][0] === 'B') {
          $max = max(array_keys($enum));
          while (!end($value) and key($value) > $max) {
            array_pop($value);
          }
          // Pad if $format's Bn's n is smaller than number of $enum members.
          // This can be when n depends on H3M->$format, as does Player->$towns
          // (B1 RoE, B2 AB+).
          $value = array_pad($value, $max + 1, false);
          $keys = [];
          foreach ($value as $key => $v) {
            $k = $cx->enum($enum, $key, $silent ? null : '$'.$prop);
            $keys[] = isset($k) ? $k : $key;
          }
          $value = array_combine($keys, $value);
        } else {
          $value = $cx->enum($enum, $value, $silent ? null : '$'.$prop);
        }
      }

      if (!strncmp($info['format'], 'Bk', 2)) {
        $value = array_keys(array_intersect($value, [true]));
      }

      $this->recordMeta($cx, $prop, [
        'offset' => $info['offset'],
        'length' => $info['length'],
        'original' => $info['value'],
        'type' => $info['format'],
      ]);

      $res[] = $value;
      $options['assign'] and $this->$prop = $value;
    }

    return $res;
  }

  // Returns full list of enum values, resolving redirected "{$name}s" and
  // adding $options' 'map' values. If $prop isn't an enum, returns null.
  protected function enum($prop, array $options = []) {
    $enumProp = $prop.'s';

    if (isset(static::$$enumProp)) {
      $enum = static::$$enumProp;

      if (is_string($enum)) {
        $enum = explode('::$', $enum, 2);
        $enum = $enum[0]::${$enum[1]};
      }

      $ref = &$options[$prop]['map'];
      $ref and $enum = $ref + $enum;

      return $enum;
    }
  }

  // Writes and converts binary data to $cx->stream according to $format.
  //
  // See unpack() above for details.
  //
  // Values are taken from own properties unless the corresponding 'value'
  // option exists ('assign' is not used - only meaningful for unpack()).
  function pack(Context $cx, $format, $options = []) {
    $fields = explode('/', $format);

    foreach ($fields as $field) {
      $format = strtok($field, ' ');
      $prop = strtok('');
      $zero = $format === 'z';

      if (!is_array($options)) {
        if (count($fields) > 1) {
          throw new \InvalidArgumentException('Non-array (value) $options is only allowed when pack()\'ing a single field.');
        }
        $options = [$prop => ['value' => $options]];
      }

      if (!$zero) {
        if (!isset($options[$prop]) or
            !array_key_exists('value', $options[$prop])) {
          $options[$prop]['value'] = $this->$prop;
        }

        $ref = &$options[$prop]['value'];
        $enum = $this->enum($prop, $options);

        if ($ref !== null) {
          if (!strncmp($format, 'Bk', 2)) {
            $ref = array_fill_keys($ref, true);
          }

          if (isset($enum)) {
            $maxKey = -1;
            $packEnum = function ($value) use ($cx, $enum, $options, $prop,
                &$maxKey) {
              $silent = !empty($options[$prop]['silent']);
              $res = $cx->packEnum($enum, $value, $silent ? null : '$'.$prop);
              $maxKey < $res and $maxKey = $res;
              return $res;
            };

            if ($format[0] === 'B') {
              $ref = array_combine(array_map($packEnum, array_keys($ref)), $ref);
              // This removes unpack()'s warning if there are more members then
              // the bitfield can fit in case those members are false. For
              // example, unavailableArtifacts is 18 if SoD+, else 17; an error
              // is only enabling an artifact unsupported in pre-SoD.
              $max = max(array_keys($enum));
              while ($maxKey > $max and empty($ref[$maxKey])) {
                unset($ref[$maxKey--]);
              }
            } else {
              $ref = $packEnum($ref);
            }
          }
        } elseif ($enum and in_array(null, $enum, true) and $format[0] !== 'B') {
          // Special case to allow default value in 'map' => [0xFF... => null].
          $ref = $cx->packEnum($enum, null);
        }
        // If null then packed value will depend on nullable '?'.
      }

      $info = $cx->unpack($field, $options, true)[$prop];

      $zero or $this->recordMeta($cx, $prop, [
        'offset' => $info['offset'],
        'length' => $info['length'],
        'original' => $info['value'],
        'type' => $info['format'],
      ]);
    }
  }

  // Returns value of $this->$prop, or $default if it's null. Emits a warning if
  // the value equals $default (and returns it anyway).
  //
  // Used when "unset" $prop is written as a special number (usually
  // 0xFF(..)FF). For example, "random" isn't a valid hero class but it's
  // serialized in the same field as 0xFF.
  protected function packDefault(Context $cx, $prop, $default) {
    if ($this->$prop === $default) {
      $cx->warning('$%s: 0x%X value is special', $prop, $default);
    }
    return $this->$prop === null ? $default : $this->$prop;
  }

  // If $this->object is set, copies that object's and/or its $details
  // properties to properties of $this (named the same).
  //
  // Allows the client either to supply an object reference and have the
  // properties retrieved automatically, or to set them by hand. For example,
  // "own town" condition specifies the town by its coordinates, that is,
  // $objectProps of ['x', 'y', 'z'].
  protected function packReferencedObject(Context $cx, array $objectProps,
      array $detailsProps = [], $detailsClass = null) {
    if (isset($this->object)) {
      $obj = $cx->h3m->objects[$this->object];

      if ($detailsClass and get_class($obj->details) !== $detailsClass) {
        $cx->warning('%s->$object (%s) must reference a %s',
          get_class($this), get_class($obj->details), $detailsClass);
      }

      foreach (array_merge($objectProps, $detailsProps) as $i => $prop) {
        $details = $i >= count($objectProps) ? $obj->details : $obj;

        if (!isset($this->$prop)) {
          $this->$prop = $details->$prop;
        } elseif (null !== $enum = $this->enum($prop)) {
          $cx->packEnumCompare($enum, $this, $prop, $details, $prop);
        } elseif ($this->$prop !== $details->$prop) {
          $cx->warning('%s->$%s mismatches %s of $object%s',
            get_class($this), $prop, $prop, $obj === $details ? '->$details' : '');
        }
      }
    }
  }

  // Reads a $charset-specific string using Context->readString().
  // Sets the string to $this->$prop and calls recordMeta().
  function readString(Context $cx, $prop) {
    $info = $cx->readString();
    $this->$prop = $info['value'];

    $this->recordMeta($cx, $prop, [
      'offset' => $info['offset'],
      'length' => $info['length'],
      'original' => $info['value'],
      'type' => 'string',
    ]);
  }

  // Writes a $charset-specific string using Context->writeString().
  // Obtains the string from $this->$prop and calls recordMeta().
  function writeString(Context $cx, $prop) {
    $info = $cx->writeString($this->$prop);

    $this->recordMeta($cx, $prop, [
      'offset' => $info['offset'],
      'length' => $info['length'],
      'original' => $info['value'],
      'type' => 'string',
    ]);
  }

  // Conditionally instantiates a sub-Structure stored in $this->$prop.
  // $factory is a key in Structure::$factories; factory() calls 'if' callable
  // for every member (giving it $options) and creates 'class' if it returns
  // truthyness, setting $_type and calling recordMeta() on the new instance.
  //
  // It's an exception if $this->$prop is not null.
  // It's a warning if multiple or no 'if' returned truthyness.
  function factory(Context $cx, $prop, $factory, $options) {
    is_array($options) and $options = (object) $options;

    if ($this->$prop) {
      throw new \InvalidArgumentException(get_class($this)."->factory() called on a non-null ${$prop} (".get_class($this->$prop).").");
    }

    foreach (self::$factories[$factory] as $name => $info) {
      if (call_user_func($info['if'], $options)) {
        if ($this->$prop) {
          $cx->warning('multiple matching candidates of factory %s: %s (used), %s (ignored), ...; %s',
            $factory, $this->$prop->_type, $name, json_encode($options));
          break;
        }

        $this->$prop = new $info['class'];
        $this->$prop->_type = $name;
        $offset = $cx->stream->offset();
        $this->$prop->readUncompressedH3M($cx, ['options' => $options]);

        $this->recordMeta($cx, $prop, [
          'class' => $info['class'],
          'offset' => $offset,
          'length' => $cx->stream->offset() - $offset,
        ]);
      }
    }

    if (!$this->$prop) {
      $cx->warning('no matching candidates of factory %s: %s',
        $factory, json_encode($options));
    }
  }

  // If $this->$prop is set, writes it to $cx (emitting a warning if it isn't an
  // instance of any 'class' in $factory).
  //
  // If $this->$prop is unset, throws if $unset is null or writes $unset string
  // in its place.
  function packFactory(Context $cx, $prop, $factory, $unset = null,
      array $options = []) {
    $obj = $this->$prop;

    if ($obj) {
      if (isset($obj->_type)) {
        $class = isset(self::$factories[$factory][$obj->_type]['class'])
                     ? self::$factories[$factory][$obj->_type]['class'] : null;
        if (get_class($obj) !== $class) {
          $cx->warning("%s->%s (%s) has \$_type of %s (factory %s) whose 'class' is different (%s)",
            get_class($this), $prop, get_class($obj), $obj->_type, $factory, $class);
        }
      }

      $found = false;

      foreach (self::$factories[$factory] as $name => $info) {
        if (get_class($obj) === $info['class']) {
          $found = true;
          // Set $_type in case this Structure is going to be serialized after
          // building H3M.
          isset($obj->_type) or $obj->_type = $name;
          break;
        }
      }

      $found or $cx->warning('%s->%s (%s) is incompatible with factory %s',
        get_class($this), $prop, get_class($obj), $factory);

      $offset = $cx->stream->offset();
      $obj->writeUncompressedH3M($cx, ['parent' => $this] + $options);

      $this->recordMeta($cx, $prop, [
        'class' => get_class($obj),
        'offset' => $offset,
        'length' => $cx->stream->offset() - $offset,
      ]);
    } elseif ($unset === null) {
      throw new \RuntimeException(get_class($this)."->$prop must be set to a product of factory $factory.");
    } else {
      $cx->stream->write($unset);
    }
  }
}

// This internal class is a parent for our classes with complex inheritance to
// allow calling parent::readH3M() regardless if the subclass' parent is
// Structure (that defines readH3M() as abstract and therefore fails such a
// call) or its descendant which has readH3M() overridden (i.e. callable).
//
// abstract class K {
//   abstract function f() {}
// }
// class KK extends K {
//   function f() {}
// }
// class KKK extends KK {
//   function f() {
//     // This works unless KKK extends K. When changing parent class, we don't
//     // want to also go through the subclass' methods to add or remove
//     // parent::f().
//     parent::f();
//   }
// }
abstract class StructureRW extends Structure {
  protected function readH3M(Context $cx, array $options) {
  }

  protected function writeH3M(Context $cx, array $options) {
  }
}

// Root object representing a H3M map, with all possible extensions ($formats).
class H3M extends Structure {
  const HERO_COUNT = 156;   // HOTRAITS.txt
  const PADDING = 124;

  // Magic numbers of all H3M formats supported by this script.
  // These are first 4 bytes of every uncompressed H3M data.
  static $formats = [
    // Official.
    0x0E => 'RoE',
    0x15 => 'AB',
    0x1C => 'SoD',
    // Modifications.
    //0x1D => 'CHR',    // XXX=I
    //0x33 => 'WoG',    // XXX=I
    // XXX=I HotA?
  ];

  static $difficultys = ['easy', 'normal', 'hard', 'expert', 'impossible'];
  static $playerss = ['red', 'blue', 'tan', 'green', 'orange', 'purple',
                       'teal', 'pink'];

  static $sizeTexts = [36 => 'small', 72 => 'medium', 108 => 'large',
                       144 => 'extraLarge'];

  // Set to Context::VERSION by Context->readUncompressedH3M().
  public $_version; // int

  public $format; // str if known, else int; on read, null (-ap) indicates totally bad input file; on write, null = SoD
  public $isPlayable; // bool; on write, null = true
  public $size; // int
  public $sizeText; // str if known, else null
  public $twoLevels; // bool
  public $name; // str, null empty
  public $description; // str, null empty
  public $difficulty; // str if known, else int
  public $maxHeroLevel; // int, null if unlimited
  public $players; // hash of 'red' => Player; on write, missing are non-playable
  public $victoryCondition; // VictoryCondition, null for normal
  public $lossCondition; // LossCondition, null for normal
  public $startingHeroes; // array of enabled HOTRAITS.TXT index; on write, null = all enabled
  public $placeholderHeroes = []; // array of HOTRAITS.TXT index
  public $unavailableArtifacts = []; // array of enabled ARTRAITS.TXT index
  public $unavailableSpells = []; // array of enabled SPTRAITS.TXT index
  public $unavailableSkills = []; // array of enabled SSTRAITS.TXT index
  public $rumors = []; // array of Rumor
  public $heroes; // hash of HOTRAITS.TXT index => Hero; on write, missing use fully default properties
  public $overworldTiles; // array of Tile; give index to tileCoordinates()
  public $underworldTiles; // array of Tile, null if !$twoLevels
  public $objects = []; // array of MapObject
  public $events = []; // array of Event

  protected function readH3M(Context $cx, array $options) {
    $this->unpack($cx, 'V format/b isPlayable/V size/b twoLevels');
    $this->readString($cx, 'name');
    $this->readString($cx, 'description');
    $this->unpack($cx, 'C difficulty');

    if (!$this->isPlayable) {
      $cx->warning('map playability flag is not set');
    }

    $cx->isOrUp('AB') and $this->unpack($cx, 'C maxHeroLevel');
    $this->maxHeroLevel or $this->maxHeroLevel = null;
    $this->sizeText = $cx->enum(static::$sizeTexts, $this->size, '$size');

    if ($cx->partial < $cx::ADDITIONAL) { return; }

    foreach (static::$playerss as $name) {
      ($this->players[$name] = new Player)->readUncompressedH3M($cx);
    }

    list($type) = $this->unpack($cx, 'C victoryType', false);
    $type === 0xFF or $this->factory($cx, 'victoryCondition', 'victory', compact('type'));

    list($type) = $this->unpack($cx, 'C lossType', false);
    $type === 0xFF or $this->factory($cx, 'lossCondition', 'loss', compact('type'));

    list($maxTeam) = $this->unpack($cx, 'C maxTeam', false);
    foreach (array_values($this->players) as $i => $player) {
      $maxTeam ? $player->unpack($cx, 'C team') : $player->team = $i;
    }

    if ($maxTeam) {
      $teams = [];
      foreach ($this->players as $p) {
        $p->isPlayable() and $teams[] = $p->team;
      }
      if (!sameMembers(array_unique($teams), range(0, $maxTeam - 1))) {
        // Usually harmless.
        $cx->warning('gaps in or out of bounds team numbers: T=%d, %s',
          $maxTeam, join(' ', $teams));
      }
    }

    foreach ($this->players as $name => $player) {
      if (!$player->isPlayable()) {
        unset($this->players[$name]);
      }
    }

    $this->unpack($cx, 'Bk'.($cx->isOrUp('AB') ? 20 : 16).' startingHeroes');
    if ($this->startingHeroes === []) {
      $cx->warning('$%s: none enabled', 'startingHeroes');
    }

    list($count) = $cx->isOrUp('AB')
      ? $this->unpack($cx, 'V placeholderHeroCount', false) : [0];

    while ($count--) {
      $this->placeholderHeroes[] = $this->unpack($cx, 'C placeholderHero', false)[0];
    }

    $customHeroes = [];

    if ($cx->isOrUp('SoD')) {
      list($count) = $this->unpack($cx, 'C customHeroCount', false);

      while ($count--) {
        ($hero = new Hero)->readCustomHeroFromUncompressedH3M($cx);
        $customHeroes[$hero->type] = $hero;
      }
    }

    $this->unpack($cx, 'z 31');

    if ($cx->isOrUp('AB')) {
      $this->unpack($cx, 'Bk'.($cx->isOrUp('SoD') ? 18 : 17).' unavailableArtifacts');
    }

    if ($cx->isOrUp('SoD')) {
      $this->unpack($cx, 'Bk9 unavailableSpells/Bk4 unavailableSkills');
    }

    list($count) = $this->unpack($cx, 'V rumorCount', false);
    while ($count--) {
      ($this->rumors[] = new Rumor)->readUncompressedH3M($cx);
    }

    $this->heroes = new Hash;

    if ($cx->isOrUp('SoD')) {
      foreach (range(0, static::HERO_COUNT - 1) as $type) {
        if ($this->unpack($cx, 'b hero', false)[0]) {
          ($hero = $this->heroes[$type] = new Hero)->readUncompressedH3M($cx);

          if (isset($customHeroes[$type])) {
            $hero->mergeFrom($customHeroes[$type], ['face', 'name', 'players']);
          }
        }
      }

      if ($diff = array_diff_key($customHeroes, publicProperties($this->heroes))) {
        $cx->warning('orphan custom hero entries (ignored): %s', join(' ', $diff));
      }
    }

    if ($cx->partial < $cx::TILES) { return; }

    for ($i = $this->size ** 2; $i--; ) {
      ($this->overworldTiles[] = new Tile)->readUncompressedH3M($cx);
    }

    if ($this->twoLevels) {
      for ($i = $this->size ** 2; $i--; ) {
        ($this->underworldTiles[] = new Tile)->readUncompressedH3M($cx);
      }
    }

    if ($cx->partial < $cx::OBJECT_KINDS) { return; }

    $kinds = [];

    list($count) = $this->unpack($cx, 'V objectCount', false);
    while ($count--) {
      ($kinds[] = new MapObject)->readUncompressedH3M($cx);
    }

    if ($cx->partial < $cx::OBJECTS) {
      foreach ($kinds as $i => $kind) { $kind->kind = $i; }
      $this->objects = $kinds;
      return;
    }

    list($count) = $this->unpack($cx, 'V objectDetailCount', false);

    while ($count--) {
      $obj = MapObject::readWithKindFromUncompressedH3M($kinds, $cx);
      $index = $obj->index = array_push($this->objects, $obj) - 1;
      // First pushing to $objects, then reading details allows seeing this
      // object in -ep output.
      $obj->readDetailsFromUncompressedH3M($cx);

      switch ($obj->group) {
        case 'town':
        case 'hero':
        case 'monster':
          $cx->objectIndex["$obj->group.$obj->x.$obj->y.$obj->z"] = $index;
      }

      if (isset($obj->details->objectID) and
          // Objects that reference others have both $objectID and $object.
          // Objects that are targets, not referrers, have only $objectID.
          !property_exists($obj->details, 'object')) {
        $cx->objectIndex[$obj->details->objectID] = $index;
      }
    }

    if ($diff = array_diff(array_keys($kinds), array_column($this->objects, 'kind'))
        and $diff !== [0, 1]) {
      $cx->warning('unused object kinds: %s', join(' ', $diff));
    }

    if ($cx->partial < $cx::EVENTS) { return; }

    list($count) = $this->unpack($cx, 'V eventCount', false);
    while ($count--) {
      ($this->events[] = new Event)->readUncompressedH3M($cx);
    }

    if ($cx->padding) {
      $padding = $cx->stream->tryRead(static::PADDING);
      if (strspn($padding, "\0") !== static::PADDING) {
        $cx->warning('no or wrong final padding: %s', bin2hex($padding));
      } elseif (strlen($cx->stream->tryRead(1))) {
        $cx->warning('extra data after end of map data');
      }
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->pack($cx, 'V format/b isPlayable/V size/b twoLevels', [
      'format' => ['value' => isset($this->format) ? $this->format : 'SoD'],
      'isPlayable' => ['value' => isset($this->isPlayable) ? $this->isPlayable : true],
    ]);

    $this->writeString($cx, 'name');
    $this->writeString($cx, 'description');
    $this->pack($cx, 'C difficulty');

    if (isset($this->isPlayable) and !$this->isPlayable) {
      $cx->warning('map playability flag is not set');
    }

    if ($cx->isOrUp('AB')) {
      $this->pack($cx, 'C? maxHeroLevel');
    } elseif ($this->maxHeroLevel) {
      $cx->warning('$%s: supported in AB+', 'maxHeroLevel');
    }

    if (isset($this->sizeText)) {
      $cx->packEnumCompare(static::$sizeTexts, $this, 'sizeText', $this, 'size');
    }

    if ($cx->partial < $cx::ADDITIONAL) { return; }

    $players = [];    // ordered and with stubs in place of disabled players
    $nextTeam = 0;
    $playable = false;

    foreach (static::$playerss as $i => $name) {
      if (isset($this->players[$name])) {
        $player = $players[] = $this->players[$name];
        $playable or $playable = $player->isPlayable();
      } else {
        $player = $players[] = new Player;
        // Since this dummy player has both $canBeHuman and $canBeComputer
        // unset, it doesn't matter what other fields are as long as they don't
        // cause warnings.
        $player->canBeHuman = $player->canBeComputer = $player->customizedTowns = false;
        $player->behavior = 0;
        $player->randomTown = true;
        $player->startingHero = new StartingHero;
        $player->startingHero->random = false;
      }
      isset($player->team) ? $player->team : $player->team = $i;
      if ($nextTeam++ !== $player->team) {
        $nextTeam = -10;
      }
      $player->writeUncompressedH3M($cx);
    }

    $playable or $cx->warning('$%s: none playable', 'players');

    if (array_diff_key($this->players, array_flip(static::$playerss))) {
      $cx->warning('$%s: extraneous members', 'players');
    }

    $this->packFactory($cx, 'victoryCondition', 'victory', "\xFF");
    $this->packFactory($cx, 'lossCondition', 'loss', "\xFF");

    if ($nextTeam > 0) {
      $this->pack($cx, 'C maxTeam', 0);
    } else {
      $teams = [];
      foreach ($players as $p) { $p->isPlayable() and $teams[] = $p->team; }
      $maxTeam = max($teams);
      if (!sameMembers(array_unique($teams), range(0, $maxTeam))) {
        $cx->warning('gaps in or out of bounds team numbers: T=%d, %s',
          $maxTeam + 1, join(' ', $teams));
      }
      $this->pack($cx, 'C maxTeam', $maxTeam + 1);
      foreach ($players as $player) {
        $player->pack($cx, 'C team');
      }
    }

    if ($this->startingHeroes === []) {
      $cx->warning('$%s: none enabled', 'startingHeroes');
    }
    $len = $cx->isOrUp('AB') ? 20 : 16;
    $sh = isset($this->startingHeroes) ? $this->startingHeroes
      : array_fill(0, $len * 8, true);
    $this->pack($cx, "Bk$len startingHeroes", ['startingHeroes' => ['value' => $sh]]);

    if ($cx->isOrUp('AB')) {
      $this->pack($cx, 'V placeholderHeroCount', count($this->placeholderHeroes));
      foreach ($this->placeholderHeroes as $hero) {
        $this->pack($cx, 'C placeholderHero', $hero);
      }
    } elseif ($this->placeholderHeroes) {
      $cx->warning('$%s: supported in AB+', 'placeholderHeroes');
    }

    if ($cx->isOrUp('SoD')) {
      $customHeroes = [];
      $count = 0;

      foreach (range(0, static::HERO_COUNT - 1) as $type) {
        $hero = $customHeroes[] = isset($this->heroes[$type])
          ? $this->heroes[$type] : new Hero;
        if (isset($hero->type) and $hero->type !== $type) {
          $cx->warning('hero $type (%d) set to %d', $hero->type, $type);
        }
        $hero->type = $type;
        $count += $hero->isCustomHero($cx);
      }

      if ($this->heroes->nextKey() >= $i) {
        $cx->warning('$%s: extraneous members', 'heroes');
      }

      $this->pack($cx, 'C customHeroCount', $count);

      foreach ($customHeroes as $hero) {
        $hero->isCustomHero($cx) and $hero->writeCustomHeroToUncompressedH3M($cx);
      }
    } else {
      foreach ($this->heroes /*Hash*/ as $hero) {
        if ($hero->isCustomHero($cx)) {
          $cx->warning('$%s: supported in SoD+', 'heroes');
          break;
        }
      }
    }

    $this->pack($cx, 'z 31');

    if ($cx->isOrUp('AB')) {
      $this->pack($cx, 'Bk'.($cx->isOrUp('SoD') ? 18 : 17).' unavailableArtifacts');
    } elseif (array_filter($this->unavailableArtifacts)) {
      $cx->warning('$%s: supported in AB+', 'unavailableArtifacts');
    }

    if ($cx->isOrUp('SoD')) {
      $this->pack($cx, 'Bk9 unavailableSpells/Bk4 unavailableSkills');
    } else {
      if (array_filter($this->unavailableSpells)) {
        $cx->warning('$%s: supported in SoD+', 'unavailableSpells');
      }
      if (array_filter($this->unavailableSkills)) {
        $cx->warning('$%s: supported in SoD+', 'unavailableSkills');
      }
    }

    $this->pack($cx, 'V rumorCount', count($this->rumors));

    foreach ($this->rumors as $rumor) {
      $rumor->writeUncompressedH3M($cx);
    }

    if ($cx->isOrUp('SoD')) {
      foreach ($customHeroes as $hero) {
        $write = $hero->isCustomHero($cx) || $hero->hasOverrides();
        $this->pack($cx, 'b hero', $write);
        $write and $hero->writeUncompressedH3M($cx);
      }
    } else {
      foreach ($this->heroes /*Hash*/ as $hero) {
        if ($hero->hasOverrides()) {
          $cx->warning('$%s: supported in SoD+', 'heroes');
          break;
        }
      }
    }

    if ($cx->partial < $cx::TILES) { return; }

    $count = $this->size ** 2;

    for ($i = 0; $i < $count; $i++) {
      $this->overworldTiles[$i]->writeUncompressedH3M($cx);
    }

    if ($this->twoLevels) {
      for ($i = 0; $i < $count; $i++) {
        $this->underworldTiles[$i]->writeUncompressedH3M($cx);
      }
    }

    $keys = array_flip(range(0, $count - 1));
    if (array_diff_key($this->overworldTiles, $keys) or
        array_diff_key($this->underworldTiles ?: [], $keys)) {
      $cx->warning('$%s: extraneous members', 'overworldTiles/underworldTiles');
    }

    if ($cx->partial < $cx::OBJECT_KINDS) { return; }

    $kinds = [];

    foreach ($this->objects as $index => $obj) {
      $obj->index = $index;
      if ($obj->details and
          property_exists($obj->details, 'objectID') and
          !property_exists($obj->details, 'object')) {
        // When compiling, $object (optional) is an index in $h3m->objects that
        // client may set to have properties like $objectID and $x provided
        // automatically. Setting $object is less error-prone than providing the
        // properties and is therefore recommended.
        //
        // Generally, $objectID can be null (use autogenerated) or a positive
        // number (client must ensure uniqueness among other objects' $objectID
        // including autogenerated ones). 0 is disallowed within .h3m because
        // it's special in RandomDwelling (0 is followed by $towns in this
        // case).
        if (!isset($obj->details->objectID)) {
          $obj->details->objectID = $index + 1;
        } elseif ($obj->details->objectID <= count($this->objects)) {
          $cx->warning('$%s: may conflict with autogenerated (1..%d)', 'objectID',
            count($this->objects));
        }
      }
      $kinds[$obj->kindKey($cx)][] = $obj;
    }

    $kinds = array_values($kinds);
    $this->pack($cx, 'V objectCount', count($kinds));
    foreach ($kinds as $kind => &$ref) {
      foreach ($ref as $obj) { $obj->kind = $kind; }
      $obj->writeUncompressedH3M($cx);
      $ref = $obj;    // for if $partial below
    }

    if ($cx->partial < $cx::OBJECTS) {
      $this->objects = $kinds;
      return;
    }

    $this->pack($cx, 'V objectDetailCount', count($this->objects));
    foreach ($this->objects as $obj) {
      $obj->writeDetailsToUncompressedH3M($cx);
    }

    if ($cx->partial < $cx::EVENTS) { return; }

    $this->pack($cx, 'V eventCount', count($this->events));
    foreach ($this->events as $event) {
      $event->writeUncompressedH3M($cx);
    }

    $cx->padding and $this->pack($cx, 'z '.static::PADDING);
  }

  // HoMM 3 specifies coordinate of object's bottom right corner, relative to
  // map's top left corner, the (0;0). This method converts it to coordinate of
  // object's top left corner. It needs the object's dimensions on map that you
  // must obtain elsewhere (OBJECTS.TXT).
  function objectCoordinates(object $obj, $width, $height) {
    $x = $obj->x - $width + 1;
    $y = $obj->y - $height + 1;
    return [$x, $y, 'x' => $x, 'y' => $y];
  }

  // Like objectCoordinates() but for tile, given its index in
  // $overworldTiles/$underworldTiles.
  //
  // Unlike with objectCoordinates(), tiles are 1x1 so $index alone is enough.
  function tileCoordinates($index) {
    $x = $index % $this->size;
    $y = intdiv($index, $this->size);
    return [$x, $y, 'x' => $x, 'y' => $y];
  }
}

class Player extends Structure {
  static $behaviors = ['random', 'warrior', 'builder', 'explorer'];

  static $townss = ['castle', 'rampart', 'tower', 'inferno', 'necropolis',
                    'dungeon', 'stronghold', 'fortress', 'conflux'];

  public $canBeHuman; // bool
  public $canBeComputer; // bool
  public $behavior; // str if known, else int
  public $customizedTowns; // bool
  public $towns; // array of 'rampart' and/or int for unknown towns
  public $randomTown; // bool; if true, $towns = ::$townss, on write, null = false
  public $startingTown; // StartingTown, null if none
  public $startingHero; // StartingHero
  public $placeholderHeroes; // int; on write, null = 0
  public $heroes = []; // array of PlayerHero

  public $team; // int; on read, set by H3M; on write, null = player index

  function isPlayable() {
    return $this->canBeHuman or $this->canBeComputer;
  }

  protected function readH3M(Context $cx, array $options) {
    $this->unpack($cx, 'b canBeHuman/b canBeComputer/C behavior');

    if ($cx->isOrUp('SoD')) {
      $this->unpack($cx, 'b customizedTowns', ['customizedTowns' =>
        ['silent' => !$this->isPlayable()]]);
    }

    $this->unpack($cx, 'Bk'.($cx->isOrUp('AB') ? 2 : 1).' towns');
    $this->unpack($cx, 'b randomTown', ['randomTown' => ['silent' => true]]);

    if ($this->randomTown) {
      $all = $this::$townss;
      $cx->isOrUp('AB') or $all = array_diff($all, ['conflux']);
      if (!sameMembers($this->towns, $all) and $this->towns !== []) {
        $cx->warning('$randomTown is on but $towns is custom: %s',
          join(' ', $this->towns));
      }
      $this->towns = $this::$townss;
    }

    if ($this->unpack($cx, 'b hasMainTown', false)[0]) {
      ($this->startingTown = new StartingTown)->readUncompressedH3M($cx);
    }

    ($this->startingHero = new StartingHero)->readUncompressedH3M($cx);
    $this->checkFlags($cx);

    if ($cx->isOrUp('AB')
        // h3mlib (parse_players.c, _parse_player_sod()) has a conundrum of
        // conditions at determining struct size, plus their structs are
        // overlapping. I think the below condition matches theirs but it trips
        // over some standard maps and seems to work if commented out.
        /*and $this->startingHero->type !== null and
          (!$cx->isOrUp('SoD') or (!$this->startingTown and
           array_filter($this->towns)))*/) {
      $this->unpack($cx, 'c placeholderHeroes');
      list($count) = $this->unpack($cx, 'V heroCount', false);

      while ($count--) {
        ($this->heroes[] = new PlayerHero)->readUncompressedH3M($cx);
      }
    }
  }

  protected function checkFlags(Context $cx) {
    if ($this->isPlayable() and $this->towns === []) {
      $cx->warning('$%s: none enabled', 'towns');
    }

    $hero = $this->startingHero;
    // See functions in h3m/h3mlib/h3m_parsing/parse_players.c.
    if (!($hero->type === null or
          // Must check ASCII-encoded name, not its UTF-8 form (which is
          // typically longer).
          ($length = $hero->_meta['name']['length'] - 4) <= 12)) {
      $cx->warning('bad combination of Player->startingHero properties: type=%s, name(%d)=%s',
        var_export($hero->type, true), $length, $hero->name);
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->pack($cx, 'b canBeHuman/b canBeComputer/C behavior');

    if ($cx->isOrUp('SoD')) {
      $this->pack($cx, 'b customizedTowns');
    } elseif ($this->customizedTowns) {
      $cx->warning('$%s: supported in SoD+', 'customizedTowns');
    }

    if ($this->randomTown) {
      if (!isset($this->towns)) {
        $this->towns = $this::$townss;
      } elseif (!sameMembers($cx->packEnumArray($this::$townss, $this->towns, '$towns'), array_keys($this::$townss))) {
        $cx->warning('$randomTown is on but $towns is custom');
      }
    }

    $this->pack($cx, 'Bk'.($cx->isOrUp('AB') ? 2 : 1).' towns');
    $this->pack($cx, 'b? randomTown');

    $this->pack($cx, 'b hasMainTown', (bool) $this->startingTown);
    $this->startingTown and $this->startingTown->writeUncompressedH3M($cx);

    $this->checkFlags($cx);
    $this->startingHero->writeUncompressedH3M($cx);

    if ($cx->isOrUp('AB')) {
      $this->pack($cx, 'c? placeholderHeroes');
      $this->pack($cx, 'V heroCount', count($this->heroes));

      foreach ($this->heroes as $hero) {
        $hero->writeUncompressedH3M($cx);
      }
    } else {
      if ($this->placeholderHeroes) {
        $cx->warning('$%s: supported in AB+', 'placeholderHeroes');
      }
      if ($this->heroes) {
        $cx->warning('$%s: supported in AB+', 'heroes');
      }
    }
  }
}

class StartingTown extends Structure {
  static $types = 'HeroWO\\H3M\\Player::$townss';

  public $createHero; // bool
  public $type; // null if random, str if known, else int
  public $x; // int
  public $y; // int
  public $z; // int

  public $object; // int town index in H3M->$objects, null if none or not found; on write, provides $type/$x/$y/$z if unset

  protected function readH3M(Context $cx, array $options) {
    if ($cx->isOrUp('AB')) {
      $this->unpack($cx, 'b createHero/C type', ['type' => ['map' => [0xFF => null]]]);
    }

    $this->unpack($cx, 'C x/C y/C z');
    $this->x += 2;    // [ ][ ][X][>][>] - town's actionable spot
  }

  function resolveH3M(Context $cx, $object = null) {
    parent::resolveH3M($cx, $object);
    $this->object = $cx->resolveObject("town.$this->x.$this->y.$this->z");

    if ($cx->is('RoE') and isset($this->object)) {
      if (isset($cx->objectIndex["hero.$this->x.$this->y.$this->z"])) {
        // The editor doesn't allow such combination.
        $cx->warning('Generate Hero is forced on in RoE but Main Town (%d;%d;%d) already has a visiting hero', $this->x, $this->y, $this->z);
      } else {
        $this->createHero = true;
      }
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->packReferencedObject($cx, ['x', 'y', 'z'], ['type'], ObjectDetails_Town::class);

    if ($cx->isOrUp('AB')) {
      $this->pack($cx, 'b createHero/C type', ['type' => ['map' => [0xFF => null]]]);
    } elseif (!$this->createHero) {
      $cx->warning('$%s: supported in AB+', 'createHero');
    }

    $this->pack($cx, 'C x/C y/C z', ['x' => ['value' => $this->x - 2]]);
  }
}

class StartingHero extends Structure {
  public $random; // bool
  public $type; // HOTRAITS.TXT index, null; avoid, may be confusing
  public $face; // int, null for default; avoid, may be confusing
  public $name; // str, null for default; avoid, may be confusing

  protected function readH3M(Context $cx, array $options) {
    $this->unpack($cx, 'b random/C type');
    $this->type === 0xFF and $this->type = null;

    if ($this->type !== null) {
      $this->unpack($cx, 'C face');
      $this->face === 0xFF and $this->face = null;
      $this->readString($cx, 'name');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->pack($cx, 'b random');
    $this->pack($cx, 'C type', $type = $this->packDefault($cx, 'type', 0xFF));

    if ($type !== 0xFF) {
      $this->pack($cx, 'C face', $this->packDefault($cx, 'face', 0xFF));
      $this->writeString($cx, 'name');
    }
  }
}

class PlayerHero extends Structure {
  public $type; // HOTRAITS.TXT index
  public $name; // str, null for default

  protected function readH3M(Context $cx, array $options) {
    $this->unpack($cx, 'C type');
    $this->readString($cx, 'name');
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->pack($cx, 'C type');
    $this->writeString($cx, 'name');
  }
}

// The using class must not define readUncompressedH3M().
//
// The using class must call parent::writeH3M() (unless abstract), then
// FactoryStructure_writeH3M(), then do its own writing.
trait FactoryStructure {
  // Must be defined by the using class.
  //const TYPE = 0;

  function readUncompressedH3M(Context $cx, array $options = []) {
    $res = parent::readUncompressedH3M($cx, $options);
    $this->checkFlags($cx);
    return $res;
  }

  protected function checkFlags(Context $cx) {
    // May be overridden by the using class. Calling this stub is optional.
  }

  protected function FactoryStructure_writeH3M(Context $cx, array $options) {
    $this->checkFlags($cx);
    $this->pack($cx, 'C type', static::TYPE);
  }
}

abstract class VictoryCondition extends Structure {
  use FactoryStructure;

  public $allowNormal; // bool
  public $applyToComputer; // bool

  protected function readH3M(Context $cx, array $options) {
    $this->unpack($cx, 'b allowNormal/b applyToComputer');
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->FactoryStructure_writeH3M($cx, $options);
    $this->pack($cx, 'b allowNormal/b applyToComputer');
  }
}

class VictoryCondition_AcquireArtifact extends VictoryCondition {
  const TYPE = 0;

  public $artifact; // ARTRAITS.TXT index

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, $cx->isOrUp('AB') ? 'v artifact' : 'C artifact');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if (!$this->allowNormal) {
      $cx->warning('potentially unsupported victory condition flag');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, $cx->isOrUp('AB') ? 'v artifact' : 'C artifact');
  }
}
Structure::$factories['victory']['artifact'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_AcquireArtifact::TYPE; },
  'class' => VictoryCondition_AcquireArtifact::class,
];

class VictoryCondition_AccumulateCreatures extends VictoryCondition {
  const TYPE = 1;

  public $creature; // OBJECTS.TXT class
  public $count; // int

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, $cx->isOrUp('AB') ? 'v creature' : 'C creature');
    $this->unpack($cx, 'V count');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, $cx->isOrUp('AB') ? 'v creature' : 'C creature');
    $this->pack($cx, 'V count');
  }
}
Structure::$factories['victory']['creatures'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_AccumulateCreatures::TYPE; },
  'class' => VictoryCondition_AccumulateCreatures::class,
];

class VictoryCondition_AccumulateResources extends VictoryCondition {
  const TYPE = 2;

  static $resources = ['wood', 'mercury', 'ore', 'sulfur', 'crystal', 'gems',
                       'gold'];

  public $resource; // str if known, else int
  public $quantity; // int

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C resource/V quantity');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C resource/V quantity');
  }
}
Structure::$factories['victory']['resources'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_AccumulateResources::TYPE; },
  'class' => VictoryCondition_AccumulateResources::class,
];

// If the using class defines writeH3M() or has abstract parent::writeH3M(), it
// must call parent::writeH3M() (unless abstract), then
// PositionalCondition_writeH3M(), then do its own writing.
//
// Same applies to readH3M().
trait PositionalCondition {
  protected $adjustX = 2;   // [ ][ ][X][>][>] - town's actionable spot
  protected $objectGroup = 'town';

  public $x; // int
  public $y; // int
  public $z; // int

  public $object; // int index in H3M->$objects, null if none or not found; on write, provides $x/$y/$z if unset

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->PositionalCondition_readH3M($cx);
  }

  protected function PositionalCondition_readH3M(Context $cx) {
    $this->unpack($cx, 'C x/C y/C z');
    $this->x += $this->adjustX;
  }

  function resolveH3M(Context $cx, $object = null) {
    parent::resolveH3M($cx, $object);

    if (isset($this->x)) {
      // H3M stores coords of object's bottom right corner except for objects
      // created as visiting heroes - their bottom right corner matches the
      // town's. Here, H3M references object by their actionable spot; we do
      // $adjustX to obtain its bottom right corner's coords but this fails for
      // visiting heroes which should be shifted by 1 more cell to the right.
      //
      // We don't know if referenced object is a visiting hero (even if we have
      // read all objects - H3M doesn't have any "visiting" flag and we rely on
      // town coords to detect it). Therefore if there's no hero on the intended
      // spot, see if there is one to the right.
      $key = function ($dx) {
        $dx += $this->x;
        return "$this->objectGroup.$dx.$this->y.$this->z";
      };
      if ($this->objectGroup === 'hero' and
          !isset($cx->objectIndex[$key(0)]) and
          isset($cx->objectIndex[$key(1)])) {
        $this->x++;
      }
      $this->object = $cx->resolveObject($key(0));
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->PositionalCondition_writeH3M($cx);
  }

  protected function PositionalCondition_writeH3M(Context $cx) {
    $class = __NAMESPACE__.'\\ObjectDetails_'.unfirst($this->objectGroup);
    $this->packReferencedObject($cx, ['x', 'y', 'z'], [], $class);
    $this->pack($cx, 'C x/C y/C z', ['x' => ['value' => $this->x - $this->adjustX]]);
  }
}

abstract class PositionalVictoryCondition extends VictoryCondition {
  use PositionalCondition;
}

// Also acts as a parent class for VictoryCondition_UpgradeTown.
class VictoryCondition_BuildGrail extends PositionalVictoryCondition {
  const TYPE = 4;

  // If all 3 of inherited $x/$y/$z are null then "in any town" is indicated.

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    if ($this->x - $this->adjustX === 0xFF and
        $this->y === 0xFF and $this->z === 0xFF) {
      $this->x = $this->y = $this->z = null;
    }
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if (!$this->allowNormal or !$this->applyToComputer) {
      $cx->warning('potentially unsupported victory condition flags');
    }
  }

  protected function PositionalCondition_writeH3M(Context $cx) {
    $x = $this->packDefault($cx, 'x', 0xFF);
    $y = $this->packDefault($cx, 'y', 0xFF);
    $z = $this->packDefault($cx, 'z', 0xFF);
    if ($x === 0xFF and $y === 0xFF and $z === 0xFF) {
      $v = ['value' => $x];
      $this->pack($cx, 'C x/C y/C z', ['x' => $v, 'y' => $v, 'z' => $v]);
    } elseif ($x === 0xFF or $y === 0xFF or $z === 0xFF) {
      throw new \RuntimeException('all of $x/$y/$z must be either set or unset.');
    } else {
      parent::PositionalCondition_writeH3M($cx);
    }
  }
}
Structure::$factories['victory']['grail'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_BuildGrail::TYPE; },
  'class' => VictoryCondition_BuildGrail::class,
];

class VictoryCondition_DefeatHero extends PositionalVictoryCondition {
  const TYPE = 5;

  protected $adjustX = 1;   // [ ][X][>] - hero's actionable spot
  protected $objectGroup = 'hero';

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if ($this->allowNormal or $this->applyToComputer) {
      $cx->warning('potentially unsupported victory condition flags');
    }
  }
}
Structure::$factories['victory']['defeatHero'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_DefeatHero::TYPE; },
  'class' => VictoryCondition_DefeatHero::class,
];

class VictoryCondition_CaptureTown extends PositionalVictoryCondition {
  const TYPE = 6;
}
Structure::$factories['victory']['captureTown'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_CaptureTown::TYPE; },
  'class' => VictoryCondition_CaptureTown::class,
];

class VictoryCondition_DefeatMonster extends PositionalVictoryCondition {
  const TYPE = 7;

  protected $adjustX = 0;   // [ ][X] - monster's actionable spot
  protected $objectGroup = 'monster';

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if (!$this->applyToComputer) {
      $cx->warning('potentially unsupported victory condition flag');
    }
  }
}
Structure::$factories['victory']['defeatMonster'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_DefeatMonster::TYPE; },
  'class' => VictoryCondition_DefeatMonster::class,
];

class VictoryCondition_UpgradeTown extends VictoryCondition_BuildGrail {
  const TYPE = 3;

  static $halls = ['town', 'city', 'capitol'];
  static $forts = ['fort', 'citadel', 'castle'];

  // If all 3 of inherited $x/$y/$z are null then "any town" is indicated.

  public $hall; // str if known, else int
  public $fort; // str if known, else int

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C hall/C fort');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if (!$this->applyToComputer) {
      $cx->warning('potentially unsupported victory condition flag');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C hall/C fort');
  }
}
Structure::$factories['victory']['upgradeTown'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_UpgradeTown::TYPE; },
  'class' => VictoryCondition_UpgradeTown::class,
];

class VictoryCondition_FlagDwellings extends VictoryCondition {
  const TYPE = 8;
}
Structure::$factories['victory']['dwellings'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_FlagDwellings::TYPE; },
  'class' => VictoryCondition_FlagDwellings::class,
];

class VictoryCondition_FlagMines extends VictoryCondition {
  const TYPE = 9;
}
Structure::$factories['victory']['mines'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_FlagMines::TYPE; },
  'class' => VictoryCondition_FlagMines::class,
];

class VictoryCondition_TransportArtifact extends PositionalVictoryCondition {
  const TYPE = 10;

  public $artifact; // ARTRAITS.TXT index

  protected function PositionalCondition_readH3M(Context $cx) {
    $this->unpack($cx, 'C artifact');
    parent::PositionalCondition_readH3M($cx);
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if (!$this->allowNormal) {
      $cx->warning('potentially unsupported victory condition flag');
    }
  }

  protected function PositionalCondition_writeH3M(Context $cx) {
    $this->pack($cx, 'C artifact');
    parent::PositionalCondition_writeH3M($cx);
  }
}
Structure::$factories['victory']['transport'] = [
  'if' => function (object $options) { return $options->type === VictoryCondition_TransportArtifact::TYPE; },
  'class' => VictoryCondition_TransportArtifact::class,
];

abstract class LossCondition extends StructureRW {
  use FactoryStructure { FactoryStructure_writeH3M as writeH3M; }
}

abstract class PositionalLossCondition extends LossCondition {
  use PositionalCondition;
}

class LossCondition_LoseTown extends PositionalLossCondition {
  const TYPE = 0;
}
Structure::$factories['loss']['town'] = [
  'if' => function (object $options) { return $options->type === LossCondition_LoseTown::TYPE; },
  'class' => LossCondition_LoseTown::class,
];

class LossCondition_LoseHero extends PositionalLossCondition {
  const TYPE = 1;

  protected $adjustX = 1;   // [ ][X][>] - hero's actionable spot
  protected $objectGroup = 'hero';
}

Structure::$factories['loss']['hero'] = [
  'if' => function (object $options) { return $options->type === LossCondition_LoseHero::TYPE; },
  'class' => LossCondition_LoseHero::class,
];

class LossCondition_TimeExpires extends LossCondition {
  const TYPE = 2;

  public $days; // int 1-based; lose when 1-based day $days + 1 dawns

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'v days');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if ($this->days < 1) {
      $cx->warning('non-positive LossCondition_TimeExpires->$days');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'v days');
  }
}
Structure::$factories['loss']['time'] = [
  'if' => function (object $options) { return $options->type === LossCondition_TimeExpires::TYPE; },
  'class' => LossCondition_TimeExpires::class,
];

class Rumor extends Structure {
  public $name; // str
  public $description; // str

  protected function readH3M(Context $cx, array $options) {
    $this->readString($cx, 'name');
    $this->readString($cx, 'description');
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->writeString($cx, 'name');
    $this->writeString($cx, 'description');
  }
}

class Hero extends Structure {
  static $playerss = 'HeroWO\\H3M\\H3M::$playerss';
  static $genders = ['male', 'female'];

  // "Custom hero" structure.
  // $type is not part of serialized data, it's only used by
  // H3M->readH3M()/writeH3M(). Client may infer it from the key in
  // $h3m->heroes.
  //public $type; // int
  public $face; // int, null for default
  public $name; // str, null for default
  public $players; // array of enabled 'red', null for all

  // Additional hero overrides.
  public $experience; // int, null for default
  public $skills; // array of Skill, null for default
  public $artifacts; // hash of 'slot' => Artifact ('backpack' is array of Artifact; doesn't include Catapult), null for default artifacts
  public $biography; // str, empty str if so defined, null for default
  public $gender; // str if known, else int, null for default
  public $spells; // array of enabled SPTRAITS.TXT index, null for default
  public $attack;     // int, null for default (then other 3 are also null)
  public $defense;    // int, null for default (then other 3 are also null)
  public $spellPower; // int, null for default (then other 3 are also null)
  public $knowledge;  // int, null for default (then other 3 are also null)

  function isCustomHero(Context $cx) {
    return isset($this->face) or
           strlen($this->name) or
           (isset($this->players) and
            !sameMembers($cx->packEnumArray(H3M::$playerss, $this->players, '$players'),
                         array_keys(H3M::$playerss)));
  }

  // This may return false while isCustomHero() may return true.
  function hasOverrides() {
    return isset($this->experience) or
           isset($this->skills) or
           isset($this->artifacts) or
           isset($this->biography) or
           isset($this->gender) or
           isset($this->spells) or
           isset($this->attack) or
           isset($this->defense) or
           isset($this->spellPower) or
           isset($this->knowledge);
  }

  function readCustomHeroFromUncompressedH3M(Context $cx) {
    $this->unpack($cx, 'C type/C face');
    $this->face === 0xFF and $this->face = null;
    $this->readString($cx, 'name');
    $this->unpack($cx, 'Bk1 players');
    sameMembers($this->players, H3M::$playerss) and $this->players = null;
    $this->checkFlags($cx);
  }

  function writeCustomHeroToUncompressedH3M(Context $cx) {
    $this->checkFlags($cx);
    $this->pack($cx, 'C type');
    $this->pack($cx, 'C face', $this->packDefault($cx, 'face', 0xFF));
    $this->writeString($cx, 'name');
    $this->pack($cx, 'Bk1 players', ['players' =>
      ['value' => isset($this->players) ? $this->players : H3M::$playerss]]);
  }

  protected function readH3M(Context $cx, array $options) {
    if ($this->unpack($cx, 'b hasExperience', false)[0]) {
      $this->unpack($cx, 'V experience');
    }

    if ($this->unpack($cx, 'b hasSkills', false)[0]) {
      $this->skills = Skill::readAllFromUncompressedH3M($cx);
    }

    if ($this->unpack($cx, 'b hasArtifacts', false)[0]) {
      $this->artifacts = Artifact::readAllFromUncompressedH3M($cx, [
        'default' => 0xFFFF,
      ]);
    }

    if ($this->unpack($cx, 'b hasBiography', false)[0]) {
      $this->readString($cx, 'biography');
      strlen($this->biography) or $this->biography = '';
    }

    $this->unpack($cx, 'C gender', ['gender' => ['map' => [0xFF => null]]]);

    if ($this->unpack($cx, 'b hasSpells', false)[0]) {
      $this->unpack($cx, 'Bk9 spells');
    }

    if ($this->unpack($cx, 'b hasPrimarySkills', false)[0]) {
      $this->unpack($cx, 'C attack/C defense/C spellPower/C knowledge');
    }

    $this->checkFlags($cx);
  }

  protected function checkFlags(Context $cx) {
    if ($this->players === []) {
      $cx->warning('$%s: none enabled', 'players');
    }

    if ($this->skills === []) {
      $cx->warning('$%s: none enabled', 'skills');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->checkFlags($cx);

    $this->pack($cx, 'b hasExperience', isset($this->experience));
    isset($this->experience) and $this->pack($cx, 'V experience');

    $this->pack($cx, 'b hasSkills', isset($this->skills));
    if (isset($this->skills)) {
      Skill::writeAllToUncompressedH3M($cx, $this->skills);
    }

    $this->pack($cx, 'b hasArtifacts', isset($this->artifacts));
    if (isset($this->artifacts)) {
      Artifact::writeAllToUncompressedH3M($cx, $this->artifacts, [
        'default' => 0xFFFF,
      ]);
    }

    $this->pack($cx, 'b hasBiography', isset($this->biography));
    isset($this->biography) and $this->writeString($cx, 'biography');

    $this->pack($cx, 'C gender', ['gender' => ['map' => [0xFF => null]]]);

    $this->pack($cx, 'b hasSpells', isset($this->spells));
    isset($this->spells) and $this->pack($cx, 'Bk9 spells');

    $set = isset($this->attack) || isset($this->defense) ||
           isset($this->spellPower) || isset($this->knowledge);
    $this->pack($cx, 'b hasPrimarySkills', $set);
    $set and $this->pack($cx, 'C attack/C defense/C spellPower/C knowledge');
  }
}

class Skill extends Structure {
  static $levels = [1 => 'basic', 'advanced', 'expert'];

  public $skill; // SSTRAITS.TXT index
  public $level; // str if known, else int

  static function readAllFromUncompressedH3M(Context $cx, $shortCount = false) {
    $res = [];
    list(, $count) = unpack($shortCount ? 'C' : 'V',
      $cx->stream->read($shortCount ? 1 : 4));

    while ($count--) {
      ($res[] = new static)->readUncompressedH3M($cx);
    }

    return $res;
  }

  static function writeAllToUncompressedH3M(Context $cx, array $skills,
      $shortCount = false) {
    $cx->stream->write(pack($shortCount ? 'C' : 'V', count($skills)));

    foreach ($skills as $skill) {
      $skill->writeUncompressedH3M($cx);
    }
  }

  protected function readH3M(Context $cx, array $options) {
    $this->unpack($cx, 'C skill/C level');
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->pack($cx, 'C skill/C level');
  }
}

class Artifact extends Structure {
  static $slots = ['head', 'shoulders', 'neck', 'rightHand', 'leftHand',
                   'torso', 'rightRing', 'leftRing', 'feet', 'misc1', 'misc2',
                   'misc3', 'misc4', 'warMachine1', 'warMachine2',
                   'warMachine3', 'warMachine4', 'spellBook', 'misc5'];

  public $artifact; // ARTRAITS.TXT index

  static function readAllFromUncompressedH3M(Context $cx, array $options = []) {
    $res = [];

    foreach (static::$slots as $slot) {
      switch ($slot) {
        case 'warMachine4':
          if (isset($options['catapult']) and !$cx->isOrUp($options['catapult'])) {
            break;
          }
        default:
          $artifact = (new static)->readUncompressedH3M($cx, $options);
          if ($artifact->artifact !== $options['default']) {
            $res[$slot] = $artifact;
          }
      }
    }

    list(, $count) = unpack('v', $cx->stream->read(2));

    while ($count--) {
      ($res['backpack'][] = new static)->readUncompressedH3M($cx, $options);
    }

    return $res;
  }

  static function writeAllToUncompressedH3M(Context $cx, array $artifacts,
      array $options = []) {
    $default = new static;
    $default->artifact = $options['default'];

    foreach (static::$slots as $slot) {
      if (isset($artifacts[$slot])) {
        $art = $artifacts[$slot];
        $art->packDefault($cx, 'artifact', $default->artifact);   // warn
      } else {
        $art = $default;
      }
      switch ($slot) {
        case 'warMachine4':
          if (isset($options['catapult']) and !$cx->isOrUp($options['catapult'])) {
            if ($art !== $default) {
              $cx->warning('Artifact in %s: supported in %s+',
                $slot, $options['catapult']);
            }
            break;
          }
        default:
          $art->writeUncompressedH3M($cx, $options);
      }
    }

    $artifacts += ['backpack' => []];
    $cx->stream->write(pack('v', count($artifacts['backpack'])));

    foreach ($artifacts['backpack'] as $art) {
      $art->writeUncompressedH3M($cx, $options);
    }

    if (array_diff_key($artifacts, array_flip(array_merge(static::$slots, ['backpack'])))) {
      $cx->warning('%s: extraneous members', 'Artifacts');
    }
  }

  protected function readH3M(Context $cx, array $options) {
    $options += ['size' => 'v'];
    $this->unpack($cx, $options['size'].' artifact');
  }

  protected function writeH3M(Context $cx, array $options) {
    $options += ['size' => 'v'];
    $this->pack($cx, $options['size'].' artifact');
  }
}

class Tile extends Structure {
  static $terrains = ['dirt', 'desert', 'grass', 'snow', 'swamp', 'rough',
                      'subterranean', 'lava', 'water', 'rock'];
  static $rivers = [null, 'clear', 'icy', 'muddy', 'lava'];
  static $roads = [null, 'dirt', 'gravel', 'cobblestone'];
  static $flagss = ['terrainFlipX', 'terrainFlipY', 'riverFlipX',
                    'riverFlipY', 'roadFlipX', 'roadFlipY', 'coast'];

  public $terrain; // str if known, else int
  public $terrainSubclass; // int
  public $terrainFlipX; // bool; on write, null = false
  public $terrainFlipY; // bool; on write, null = false

  public $river; // null if none, str if known, else int
  public $riverSubclass; // int, null if $river is null
  public $riverFlipX; // bool, null if $river is null; on write, null = false
  public $riverFlipY; // bool, null if $river is null; on write, null = false

  public $road; // null if none, str if known, else int
  public $roadSubclass; // int, null if $road is null
  public $roadFlipX; // bool, null if $road is null; on write, null = false
  public $roadFlipY; // bool, null if $road is null; on write, null = false

  public $coast; // bool; on write, null = false

  protected function readH3M(Context $cx, array $options) {
    $this->unpack($cx,
      'C terrain/C terrainSubclass/C river/C riverSubclass/C road/C roadSubclass');

    list($flags) = $this->unpack($cx, 'B1 flags', false);
    foreach ($flags as $k => $v) { $this->$k = $v; }

    isset($this->river) or $this->riverSubclass = $this->riverFlipX = $this->riverFlipY = null;
    isset($this->road) or $this->roadSubclass = $this->roadFlipX = $this->roadFlipY = null;
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->pack($cx,
      'C terrain/C terrainSubclass/C? river/C? riverSubclass/C? road/C? roadSubclass');

    $flags = [];
    foreach (static::$flagss as $prop) { $flags[] = $this->$prop ?: false; }
    $flags = array_combine(static::$flagss, $flags);
    $this->pack($cx, 'B1 flags', ['flags' => ['value' => $flags]]);

    if ((!isset($this->river) and (isset($this->riverSubclass) or $this->riverFlipX or $this->riverFlipY)) or
        (isset($this->river) and !isset($this->riverSubclass)) or
        (!isset($this->road) and (isset($this->roadSubclass) or $this->roadFlipX or $this->roadFlipY)) or
        (isset($this->road) and !isset($this->roadSubclass))) {
      $cx->warning('null $river/$road with set flag(s)');
    }
  }
}

class MapObject extends Structure {
  static $allowedLandscapess = ['dirt', 'desert', 'grass', 'snow', 'swamp',
                                'rock', 'underground', 'lava', 'water'];
  static $landscapeGroupss = 'HeroWO\\H3M\\MapObject::$allowedLandscapess';
  static $groups = ['terrain', 'town', 'monster', 'hero', 'artifact',
                    'treasure'];

  public $index; // int key in H3M->$objects; don't set

  // These properties have identical values for objects with the same $kind.
  public $def; // str
  public $passability; // hash of 'X_Y' => bool; treat missing coords as passable
  public $actionability; // as $passability; treat missing as non-actionable
  public $allowedLandscapes; // array of 'lava' and/or int for unknown
  public $landscapeGroups; // as $allowedLandscapes
  public $class; // int OBJECTS.TXT class
  public $subclass; // int OBJECTS.TXT subclass
  public $group; // str if known, else int
  public $ground; // bool; on write, null = false

  // Properties different for every object.
  public $x; // int
  public $y; // int
  public $z; // int
  public $kind; // int (compare with other $kind-s of the same map or disregard); don't set
  public $details; // ObjectDetails (never ObjectDetails_None), null

  static function unpackPassability(array $a, $trim = null,
      Context $pack = null) {
    static $keys;

    if (!$keys) {
      for ($y = 0; $y < 6; $y++) {
        for ($x = 0; $x < 8; $x++) {
          $keys[] = $x."_$y";
        }
      }
    }

    if ($pack) {
      if (array_diff(array_keys($a), $keys)) {
        $pack->warning('$%s: extraneous members', $trim ? 'passability'
          : 'actionability');
      }
      return array_map(function ($x_y) use ($a, $trim) {
        return isset($a[$x_y]) ? $a[$x_y] : $trim;
      }, array_reverse($keys));
    } else {
      $a = array_combine($keys, array_reverse($a));
      // Remove from tail for compactness; keep non-tail $trim values for
      // clarity.
      while ($a and end($a) === $trim) { array_pop($a); }
      return $a;
    }
  }

  static function readWithKindFromUncompressedH3M(array $kinds, Context $cx) {
    $object = new MapObject;
    $object->unpack($cx, 'C x/C y/C z/V kind');

    return (clone $kinds[$object->kind])
      ->mergeFrom($object, ['x', 'y', 'z', 'kind']);
  }

  // Returns a string that is the same for all MapObject-s with the same values
  // for properties affected by $kind (after normalization). Technically, every
  // object may have its own kind entry for the same effect but this reduces map
  // file size.
  function kindKey(Context $cx) {
    static $f;
    static $stream;
    if (!$f) {
      $stream = new PhpStream($f = fopen('php://memory', 'w+b'));
    } else {
      rewind($f);
      $stream->motions = [];    // can grow quickly
    }
    $old = $cx->stream;
    $cx->stream = $stream;
    try {
      $this->writeH3M($cx, []);
      return stream_get_contents($f, ftell($f), 0);
    } finally {
      $cx->stream = $old;
    }
  }

  // Reads object kind info ("object attributes").
  protected function readH3M(Context $cx, array $options) {
    // h3mlib (HD Edition's maps) stores junk in the last OA entry (which is
    // unused; the last but one OA is also unused but it's fine). In particular,
    // that entry ends on "h3mlib by potmdehex" (which takes part of "z 16" and
    // thus is not visible in -pO output) and has garbage in $def that iconv has
    // trouble coping with (-s ru may help).
    $this->readString($cx, 'def');
    $this->unpack($cx,
      'B6 passability/B6 actionability/Bk2 allowedLandscapes/Bk2 landscapeGroups');

    $this->checkFlags($cx, $this->passability, $this->actionability);
    $this->passability = static::unpackPassability($this->passability, true);
    $this->actionability = static::unpackPassability($this->actionability, false);

    $this->unpack($cx, 'V class/V subclass/C group/b ground/z 16');
  }

  protected function checkFlags(Context $cx, array $passability,
      array $actionability) {
    foreach ($actionability as $i => $actionable) {
      if ($actionable and $passability[$i]) {
        $cx->warning("object's actionable bit(s) set for passable ones: P=%s, A=%s",
          join(array_map('intval', $passability)),
          join(array_map('intval', $actionability)));
        break;
      }
    }

    if ($this->allowedLandscapes === []) {
      $cx->warning('$%s: none enabled', 'allowedLandscapes');
    }

    if ($this->landscapeGroups === []) {
      $cx->warning('$%s: none enabled', 'landscapeGroups');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    $passability = static::unpackPassability($this->passability, true, $cx);
    $actionability = static::unpackPassability($this->actionability, false, $cx);
    $this->checkFlags($cx, $passability, $actionability);

    $this->writeString($cx, 'def');

    $this->pack($cx, 'B6 passability/B6 actionability/Bk2 allowedLandscapes/Bk2 landscapeGroups', [
      'passability' => ['value' => $passability],
      'actionability' => ['value' => $actionability],
    ]);

    $this->pack($cx, 'V class/V subclass/C group/b? ground/z 16');
  }

  function readDetailsFromUncompressedH3M(Context $cx) {
    $this->unpack($cx, 'z 5');
    $this->factory($cx, 'details', 'objectDetails', $this);

    if (get_class($this->details) === ObjectDetails_None::class) {
      $this->details = null;
    }

    return $this;
  }

  function writeDetailsToUncompressedH3M(Context $cx) {
    $this->pack($cx, 'C x/C y/C z/V kind');
    $this->pack($cx, 'z 5');

    if ($this->details) {
      if (get_class($this->details) === ObjectDetails_None::class) {
        $cx->warning('use null, not %s', get_class($this->details));
      } else {
        $this->packFactory($cx, 'details', 'objectDetails');
      }
    }
  }
}

abstract class ObjectDetails extends StructureRW {}

class ObjectDetails_HeroPlaceholder extends ObjectDetails {
  static $owners = 'HeroWO\\H3M\\H3M::$playerss';

  public $owner; // str if known, else int
  // Exactly one of these two is non-null at a time.
  public $hero; // HOTRAITS.TXT index, null
  public $powerRating; // int, null

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C owner/C hero');
    if ($this->hero === 0xFF) {
      $this->hero = null;
      $this->unpack($cx, 'C powerRating');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C owner');
    $this->pack($cx, 'C hero', $hero = $this->packDefault($cx, 'hero', 0xFF));
    if ($hero === 0xFF) {
      $this->pack($cx, 'C powerRating');  // suppress your power level!
    } elseif (isset($this->powerRating)) {
      $cx->warning('null $hero with set $powerRating');
    }
  }
}
Structure::$factories['objectDetails']['heroPlaceholder'] = [
  'if' => function (object $options) { return $options->class === 214; },
  'class' => ObjectDetails_HeroPlaceholder::class,
];

// Also acts as a parent class for ObjectDetails_SeerHut.
class ObjectDetails_QuestGuard extends ObjectDetails {
  public $quest; // Quest, null if no quest
  public $deadline; // int 0-based days, null no $quest or no deadline
  public $firstMessage; // str, null if no $quest
  public $unmetMessage; // str, null if no $quest
  public $metMessage; // str, null if no $quest

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    list($type) = $this->unpack($cx, 'C type', false);

    if ($type) {
      $this->factory($cx, 'quest', 'quest', compact('type'));

      $this->unpack($cx, 'V deadline');
      $this->deadline === 0xFFFFFFFF and $this->deadline = null;

      $this->readString($cx, 'firstMessage');
      $this->readString($cx, 'unmetMessage');
      $this->readString($cx, 'metMessage');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);

    $this->packFactory($cx, 'quest', 'quest', "\0");

    if ($this->quest) {
      $this->pack($cx, 'V deadline', $this->packDefault($cx, 'deadline', 0xFFFFFFFF));

      $this->writeString($cx, 'firstMessage');
      $this->writeString($cx, 'unmetMessage');
      $this->writeString($cx, 'metMessage');
    }
  }
}
Structure::$factories['objectDetails']['questGuard'] = [
  'if' => function (object $options) { return $options->class === 215; },
  'class' => ObjectDetails_QuestGuard::class,
];

abstract class Quest extends StructureRW {
  use FactoryStructure { FactoryStructure_writeH3M as writeH3M; }
}

class Quest_Level extends Quest {
  const TYPE = 1;

  public $level; // int 1-based

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V level');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if ($this->level < 1) {
      $cx->warning('non-positive Quest_Level->$level');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'V level');
  }
}
Structure::$factories['quest']['experience'] = [
  'if' => function (object $options) { return $options->type === Quest_Level::TYPE; },
  'class' => Quest_Level::class,
];

class Quest_PrimarySkills extends Quest {
  const TYPE = 2;

  public $attack; // int
  public $defense; // int
  public $spellPower; // int
  public $knowledge; // int

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C attack/C defense/C spellPower/C knowledge');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C attack/C defense/C spellPower/C knowledge');
  }
}
Structure::$factories['quest']['primarySkills'] = [
  'if' => function (object $options) { return $options->type === Quest_PrimarySkills::TYPE; },
  'class' => Quest_PrimarySkills::class,
];

abstract class DefeatQuest extends Quest {
  public $objectID; // int

  public $object; // int index in H3M->$objects, null if none or not found; on write, provides $objectID if unset

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V objectID');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->packReferencedObject($cx, [], ['objectID']);
    $this->pack($cx, 'V objectID');
  }
}

class Quest_DefeatHero extends DefeatQuest {
  const TYPE = 3;

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    if (isset($this->object) and
        get_class($cx->h3m->objects[$this->object]->details) !== ObjectDetails_Hero::class) {
      $cx->warning('$%s: must be a hero', 'object');
    }
  }
}
Structure::$factories['quest']['defeatHero'] = [
  'if' => function (object $options) { return $options->type === Quest_DefeatHero::TYPE; },
  'class' => Quest_DefeatHero::class,
];

class Quest_DefeatMonster extends DefeatQuest {
  const TYPE = 4;

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    if (isset($this->object) and
        get_class($cx->h3m->objects[$this->object]->details) !== ObjectDetails_Monster::class) {
      $cx->warning('$%s: must be a monster', 'object');
    }
  }
}
Structure::$factories['quest']['defeatMonster'] = [
  'if' => function (object $options) { return $options->type === Quest_DefeatMonster::TYPE; },
  'class' => Quest_DefeatMonster::class,
];

class Quest_Resources extends Quest {
  const TYPE = 7;

  public $resources; // Resources (can't be negative)

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    ($this->resources = new Resources)->readUncompressedH3M($cx);
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->resources->writeUncompressedH3M($cx);
  }
}
Structure::$factories['quest']['resources'] = [
  'if' => function (object $options) { return $options->type === Quest_Resources::TYPE; },
  'class' => Quest_Resources::class,
];

class Resources extends Structure implements \Countable {
  public $wood; // int; on write, null = 0
  public $mercury; // int; on write, null = 0
  public $ore; // int; on write, null = 0
  public $sulfur; // int; on write, null = 0
  public $crystal; // int; on write, null = 0
  public $gems; // int; on write, null = 0
  public $gold; // int; on write, null = 0

  protected function readH3M(Context $cx, array $options) {
    $s = ($options + ['size' => 'V'])['size'];
    $this->unpack($cx,
      "$s wood/$s mercury/$s ore/$s sulfur/$s crystal/$s gems/$s gold");
  }

  protected function writeH3M(Context $cx, array $options) {
    if (empty($options['negative'])) {
      foreach (publicProperties($this) as $k => $v) {
        if ($v < 0) {
          $cx->warning('$%s: negative (set to 0)', $k);
          $this->$k = 0;
        }
      }
    }

    $s = ($options + ['size' => 'V'])['size'].'?';
    $this->pack($cx,
      "$s wood/$s mercury/$s ore/$s sulfur/$s crystal/$s gems/$s gold");
  }

  // $empty = !count($resources);
  #[\ReturnTypeWillChange]
  function count() {
    return $this->wood   + $this->mercury + $this->ore  +
           $this->sulfur + $this->crystal + $this->gems + $this->gold;
  }
}

class Quest_BeHero extends Quest {
  const TYPE = 8;

  public $hero; // HOTRAITS.TXT index

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C hero');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C hero');
  }
}
Structure::$factories['quest']['beHero'] = [
  'if' => function (object $options) { return $options->type === Quest_BeHero::TYPE; },
  'class' => Quest_BeHero::class,
];

class Quest_BePlayer extends Quest {
  const TYPE = 9;

  static $players = 'HeroWO\\H3M\\H3M::$playerss';

  public $player; // str if known, else int

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C player');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C player');
  }
}
Structure::$factories['quest']['bePlayer'] = [
  'if' => function (object $options) { return $options->type === Quest_BePlayer::TYPE; },
  'class' => Quest_BePlayer::class,
];

class Quest_Creatures extends Quest {
  const TYPE = 6;

  public $creatures; // hash of slot => Creature (without gaps)

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    list($count) = $this->unpack($cx, 'C count', false);
    $this->creatures =
      Creature::readAllFromUncompressedH3M($count, $cx, ['size' => 'v']);
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if (!count($this->creatures)) {
      $cx->warning('$%s: none enabled', 'creatures');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C count', $this->creatures->nextKey());
    Creature::writeAllToUncompressedH3M($this->creatures, $cx,
      ['size' => 'v', 'compact' => true]);
  }
}
Structure::$factories['quest']['creatures'] = [
  'if' => function (object $options) { return $options->type === Quest_Creatures::TYPE; },
  'class' => Quest_Creatures::class,
];

class Creature extends Structure {
  public $creature; // CRTRAITS.TXT index, null if no creature
  public $count; // int, null if no creature

  // Returned Hash may have gaps and won't have members with null $creature.
  static function readAllFromUncompressedH3M($count, Context $cx,
      array $options = []) {
    $res = new Hash;

    for ($i = 0; $i < $count; $i++) {
      ($creature = new static)->readUncompressedH3M($cx, $options);
      isset($creature->creature) and $res[$i] = $creature;
    }

    return $res;
  }

  static function writeAllToUncompressedH3M(Hash $creatures, Context $cx,
      array $options = []) {
    $max = $creatures->nextKey();
    $count = ($options + ['count' => $max])['count'];
    for ($i = 0; $i < $count; $i++) {
      $creature = isset($creatures[$i]) ? $creatures[$i] : new static;
      if (!empty($options['compact']) and !isset($creature->creature)) {
        // Not a warning since $creatures count was written prior to calling
        // this method; we can't change it now and writing 0xFFFF may be
        // disallowed in this context.
        throw new \RuntimeException('Creature Hash cannot have gaps.');
      }
      $creature->writeUncompressedH3M($cx, $options);
    }
    for (; $i < $max; $i++) {
      if (isset($creatures[$i]->creature)) {
        $cx->warning('Creature slot %d is over limit (%d)', $i, $count);
      }
    }
  }

  protected function readH3M(Context $cx, array $options) {
    $options += ['size' => $cx->isOrUp('AB') ? 'v' : 'C'];
    $this->unpack($cx, "$options[size] creature/v count");

    if ($this->creature === ($options['size'] === 'v' ? 0xFFFF : 0xFF)) {
      $this->creature = $this->count = null;
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    $options += ['size' => $cx->isOrUp('AB') ? 'v' : 'C'];
    $default = $options['size'] === 'v' ? 0xFFFF : 0xFF;
    $creature = $this->packDefault($cx, 'creature', $default);
    if (($creature === $default) !== ($this->count === null)) {
      $cx->warning('one of $creature/$count is set while the other is not');
    }
    $this->count === null and $creature = $default;
    $this->pack($cx, "$options[size] creature", $creature);
    $this->pack($cx, 'v? count');
  }
}

class Quest_Artifacts extends Quest {
  const TYPE = 5;

  public $artifacts; // array of Artifact

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    list($count) = $this->unpack($cx, 'C count', false);

    while ($count--) {
      ($this->artifacts[] = new Artifact)->readUncompressedH3M($cx);
    }
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if (!$this->artifacts) {
      $cx->warning('$%s: none enabled', 'artifacts');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);

    $this->pack($cx, 'C count', count($this->artifacts));

    foreach ($this->artifacts as $art) {
      $art->writeUncompressedH3M($cx);
    }
  }
}
Structure::$factories['quest']['artifacts'] = [
  'if' => function (object $options) { return $options->type === Quest_Artifacts::TYPE; },
  'class' => Quest_Artifacts::class,
];

// Also acts as a parent class for ObjectDetails_Event.
class ObjectDetails_PandoraBox extends ObjectDetails {
  // First, player sees $guard->message, then prompt for opening the Box, then a
  // message about guards and a combat (if guards present), then finally a
  // message. If the Box is empty (all bonuses null) then the final message uses
  // a hardcoded text.
  public $guard; // Guarded, null
  public $experience; // int, null
  public $spellPoints; // int (can be negative), null
  public $morale; // int (can be negative), null
  public $luck; // int (can be negative!), null
  public $resources; // Resources (can be negative), null
  public $attack; // int, null
  public $defense; // int, null
  public $spellPower; // int, null
  public $knowledge; // int, null
  public $skills; // array of Skill, null
  public $artifacts; // array of Artifact, null
  public $spells; // array of SPTRAITS.TXT indexes, null
  public $creatures; // hash of slot => Creature (without gaps), null

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    if ($this->unpack($cx, 'b isGuarded', false)[0]) {
      ($this->guard = new Guarded)->readUncompressedH3M($cx);
    }

    $this->unpack($cx, 'V experience/S spellPoints/c morale/c luck');
    ($this->resources = new Resources)->readUncompressedH3M($cx, ['size' => 'S']);
    $this->unpack($cx, 'C attack/C defense/C spellPower/C knowledge');
    $this->skills = Skill::readAllFromUncompressedH3M($cx, true);

    list($count) = $this->unpack($cx, 'C artifactCount', false);
    while ($count--) {
      ($this->artifacts[] = new Artifact)->readUncompressedH3M($cx, [
        'size' => $cx->isOrUp('AB') ? 'v' : 'C',
      ]);
    }

    list($count) = $this->unpack($cx, 'C spellCount', false);
    while ($count--) {
      $this->spells[] = $this->unpack($cx, 'C spell', false)[0];
    }

    list($count) = $this->unpack($cx, 'C creatureCount', false);
    $this->creatures = Creature::readAllFromUncompressedH3M($count, $cx);

    $this->unpack($cx, 'z 8');

    foreach (publicProperties($this) as $prop => $value) {
      if ((new \ReflectionProperty($this, $prop))->class === static::class and
          $prop !== 'guard' and $prop !== '_type' and
          $value and (is_int($value) ? !$value : !count($value))) {
        $this->$prop = null;
      }
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);

    $this->pack($cx, 'b isGuarded', (bool) $this->guard);
    $this->guard and $this->guard->writeUncompressedH3M($cx);

    $this->pack($cx, 'V? experience/S? spellPoints/c? morale/c? luck');
    ($this->resources ?: new Resources)->writeUncompressedH3M($cx,
      ['size' => 'S', 'negative' => true]);
    $this->pack($cx, 'C? attack/C? defense/C? spellPower/C? knowledge');
    Skill::writeAllToUncompressedH3M($cx, $this->skills ?: [], true);

    $this->pack($cx, 'C artifactCount', count($this->artifacts ?: []));
    foreach ($this->artifacts ?: [] as $art) {
      $art->writeUncompressedH3M($cx, [
        'size' => $cx->isOrUp('AB') ? 'v' : 'C',
      ]);
    }

    $this->pack($cx, 'C spellCount', count($this->spells ?: []));
    foreach ($this->spells ?: [] as $spell) {
      $this->pack($cx, 'C spell', $spell);
    }

    $this->pack($cx, 'C creatureCount', $this->creatures
      ? $this->creatures->nextKey() : 0);

    if ($this->creatures) {
      Creature::writeAllToUncompressedH3M($this->creatures, $cx, ['compact' => true]);
    }

    $this->pack($cx, 'z 8');
  }
}
Structure::$factories['objectDetails']['pandoraBox'] = [
  'if' => function (object $options) { return $options->class === 6; },
  'class' => ObjectDetails_PandoraBox::class,
];

class Guarded extends Structure {
  public $message; // str, null if show no message
  public $creatures; // hash of slot => Creature, null if not guarded

  protected function readH3M(Context $cx, array $options) {
    $this->readString($cx, 'message');

    if ($this->unpack($cx, 'b hasCreatures', false)[0]) {
      $this->creatures = Creature::readAllFromUncompressedH3M(7, $cx);
    }

    $this->unpack($cx, 'z 4');
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->writeString($cx, 'message');

    $this->pack($cx, 'b hasCreatures', (bool) $this->creatures);

    if ($this->creatures) {
      Creature::writeAllToUncompressedH3M($this->creatures, $cx, ['count' => 7]);
    }

    $this->pack($cx, 'z 4');
  }
}

class ObjectDetails_Event extends ObjectDetails_PandoraBox {
  static $playerss = 'HeroWO\\H3M\\H3M::$playerss';

  public $players; // array of enabled 'red'; on write, null = all
  public $applyToComputer; // bool
  public $removeAfterVisit; // bool

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'Bk1 players/b applyToComputer/b removeAfterVisit/z 4');
    $this->checkFlags($cx);
  }

  protected function checkFlags(Context $cx) {
    if ($this->players === []) {
      $cx->warning('$%s: none enabled', 'players');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->checkFlags($cx);
    $this->pack($cx, 'Bk1 players/b applyToComputer/b removeAfterVisit/z 4',
      ['players' => ['value' => isset($this->players) ? $this->players : H3M::$playerss]]);
  }
}
Structure::$factories['objectDetails']['event'] = [
  'if' => function (object $options) { return $options->class === 26; },
  'class' => ObjectDetails_Event::class,
];

class ObjectDetails_Sign extends ObjectDetails {
  public $message;  // str, null

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->readString($cx, 'message');
    $this->unpack($cx, 'z 4');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->writeString($cx, 'message');
    $this->pack($cx, 'z 4');
  }
}
Structure::$factories['objectDetails']['sign'] = [
  'if' => function (object $options) {
    return in_array($options->class, [59, 91]);
  },
  'class' => ObjectDetails_Sign::class,
];

class ObjectDetails_Garrison extends ObjectDetails {
  static $owners = 'HeroWO\\H3M\\H3M::$playerss';

  public $owner; // null if unowned, str if known, else int
  public $creatures; // hash of slot => Creature; on write, may be null
  public $canTake = true; // bool

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V owner', ['owner' => ['map' => [0xFF => null]]]);
    $this->creatures = Creature::readAllFromUncompressedH3M(7, $cx);
    $cx->isOrUp('AB') and $this->unpack($cx, 'b canTake');
    $this->unpack($cx, 'z 8');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'V owner', ['owner' => ['map' => [0xFF => null]]]);
    Creature::writeAllToUncompressedH3M($this->creatures ?: new Hash, $cx, ['count' => 7]);
    if ($cx->isOrUp('AB')) {
      $this->pack($cx, 'b canTake');
    } elseif (!$this->canTake) {
      $cx->warning('$%s: supported in AB+', 'canTake');
    }
    $this->pack($cx, 'z 8');
  }
}
Structure::$factories['objectDetails']['garrison'] = [
  'if' => function (object $options) {
    return in_array($options->class, [33, 219]);
  },
  'class' => ObjectDetails_Garrison::class,
];

class ObjectDetails_Grail extends ObjectDetails {
  public $radius; // int, 0 fixed spot; on write, null = 0

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V radius');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'V? radius');
  }
}
Structure::$factories['objectDetails']['grail'] = [
  'if' => function (object $options) { return $options->class === 36; },
  'class' => ObjectDetails_Grail::class,
];

class ObjectDetails_Ownable extends ObjectDetails {
  static $owners = 'HeroWO\\H3M\\H3M::$playerss';

  public $owner; // null if unowned, str if known, else int

  public $resource; // null non-mine, str if known, else int; determined by MapObject->$class

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V owner', ['owner' => ['map' => [0xFF => null]]]);

    if ($options['options']->class === 53) {
      $this->resource = $cx->enum(VictoryCondition_AccumulateResources::$resources,
                                  $options['options']->subclass, '$resource');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'V owner', ['owner' => ['map' => [0xFF => null]]]);

    if (isset($this->resource)) {
      if ($options['parent']->class !== 53) {
        $cx->warning('$%s: set for non-mine', 'resource');
      } else {
        $cx->packEnumCompare(VictoryCondition_AccumulateResources::$resources,
          $this, 'resource', $options['parent'], 'subclass');
      }
    }
  }
}
Structure::$factories['objectDetails']['ownable'] = [
  'if' => function (object $options) {
    return ($options->class === 53 and $options->subclass !== 7) or
           in_array($options->class, [17, 18, 19, 20, // META_OBJECT_DWELLING
                                      42, 87]);
  },
  'class' => ObjectDetails_Ownable::class,
];

class ObjectDetails_AbandonedMine extends ObjectDetails {
  static $potentialResourcess = 'HeroWO\\H3M\\VictoryCondition_AccumulateResources::$resources';

  // Despite the editor's help stating that: "Once the troglodytes are defeated,
  // one of six resources is randomly chosen by the computer...", it seems HoMM
  // 3 chooses it when the game starts. If you save a game, defeat Troglodytes,
  // load, defeat, etc. you will always get the same type of mine.
  public $potentialResources;   // array of 'wood', int unknown resources that this mine may transform to; on write, null = all but 'wood'

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'Bk4 potentialResources');
    $this->checkFlags($cx);
  }

  protected function checkFlags(Context $cx) {
    if ($this->potentialResources === []) {
      $cx->warning('$%s: none enabled', 'potentialResources');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->checkFlags($cx);
    $res = $this->potentialResources;
    $this->pack($cx, 'Bk4 potentialResources', $res ?:
      array_diff(VictoryCondition_AccumulateResources::$resources, ['wood']));
  }
}
Structure::$factories['objectDetails']['abandonedMine'] = [
  'if' => function (object $options) {
    return $options->class === 220 or
           ($options->class === 53 and $options->subclass === 7);
  },
  'class' => ObjectDetails_AbandonedMine::class,
];

class ObjectDetails_None extends ObjectDetails {
  // Read by factory(). Never written.
}
Structure::$factories['objectDetails']['none'] = [
  'if' => function (object $options) {
    return false !== strpos(
      ' 8'.
      ' 124 21 46 125 176 175'.
      ' 222 224 225 226 227 228 229 231 223 230 139 141 142 144 145 146'.
      ' 0 40 114 115 116 117 118 119 120 121 122 123 126 127 128 129 130 131 132'.
      ' 133 134 135 136 137 138 143 147 148 149 150 151 152 153 154 155 156 157'.
      ' 158 159 160 161'.
      ' 165 166 167 168 169 170 171 172 173 174 177 178 179 180 181 182 183 184'.
      ' 185 186 187 188 189 190 191 192 193 194 195 196 197 198 199 200 201 202'.
      ' 203 204 205 206 207 208 209 210 211'.
      ' 2 3 4 7 13 11 14 15 16 22 23 24 25 27 28 30 31 32 35 37 38 39 41'.
      ' 47 48 49 51 52 55 56 57 58 60 61 64 9 10 78 80 84 85 92 94 95 96'.
      ' 97 99 100 102 104 105 106 107 108 109 110 111 112 50 1'.
      ' 221 63 212 213 43 44'.
      ' 12 29 82 86 101'.
      ' 45'.
      ' 103 ',
      " $options->class "
    );
  },
  'class' => ObjectDetails_None::class,
];

// Applies to random and regular towns alike.
class ObjectDetails_Town extends ObjectDetails {
  static $owners = 'HeroWO\\H3M\\H3M::$playerss';
  static $formations = ['spread', 'grouped'];

  static $builts = [
    // Byte 1
    'townHall', 'cityHall', 'capitol', 'fort',
    'citadel', 'castle', 'tavern', 'blacksmith',
    // Byte 2
    'marketplace',
    'resourceSiloWO resourceSiloC resourceSiloJ resourceSiloM resourceSiloS',
    'artifactMerchants', 'mageGuild1',
    'mageGuild2', 'mageGuild3',
    'mageGuild4', 'mageGuild5',
    // Byte 3
    'shipyard',
    // Assuming "Aurora Boreali>a<s" is a typo.
    'grail colossus spiritGuardian skyship deityOfFire soulPrison guardianOfEarth warlordMonument carnivorousPlant auroraBorealis',
    // The editor has a mistake in Town | Buildings for Fortress: if you enable
    // cageOfWarlords it creates bloodObelisk, and vice versa. This bug is
    // present in SoD and Complete versions (both ship with h3maped.exe v3.0.0).
    'lighthouse mysticPond library brimstoneStormclouds coverOfDarkness manaVortex escapeTunnel cageOfWarlords magicUniversity',
    'brotherhoodOfSword fountainOfFortune wallOfKnowledge castleGate necromancyAmplifier portalOfSummoning freelancerGuild glyphsOfFear',
    'stables treasury lookoutTower orderOfFire skeletonTransformer battleScholarAcademy ballistaYard bloodObelisk',
    'hallOfValhalla',
    'dwelling1 guardhouse centaurStables workshop impCrucible cursedTemple warren goblinBarracks gnollHut magicLantern',
    'dwelling1U guardhouseU centaurStablesU workshopU impCrucibleU cursedTempleU warrenU goblinBarracksU gnollHutU magicLanternU',
    // Byte 4
    'horde1 birthingPools unearthedGraves mushroomRings messHall captainQuarters gardenOfLife',
    'dwelling2 archerTower dwarfCottage parapet hallOfSins graveyard harpyLoft wolfPen lizardDen altarOfAir',
    'dwelling2U archerTowerU dwarfCottageU parapetU hallOfSinsU graveyardU harpyLoftU wolfPenU lizardDenU altarOfAirU',
    'horde2 minerGuild sculptorWings',
    'dwelling3 griffinTower homestead golemFactory kennels tombOfSouls pillarOfEyes orcTower serpentFlyHive altarOfWater',
    'dwelling3U griffinTowerU homesteadU golemFactoryU kennelsU tombOfSoulsU pillarOfEyesU orcTowerU serpentFlyHiveU altarOfWaterU',
    'horde3 cages griffinBastion',
    'dwelling4 barracks enchantedSpring mageTower demonGate estate chapelOfStilledVoices ogreFort basiliskPit altarOfFire',
    // Byte 5
    'dwelling4U barracksU enchantedSpringU mageTowerU demonGateU estateU chapelOfStilledVoicesU ogreFortU basiliskPitU altarOfFireU',
    // May be only present on random towns because HoMM 3 has no buildings that
    // boost production of 4th level creatures.
    'horde4',
    'dwelling5 monastery dendroidArches altarOfWishes hellHole mausoleum labyrinth cliffNest gorgonLair altarOfEarth',
    'dwelling5U monasteryU dendroidArchesU altarOfWishesU hellHoleU mausoleumU labyrinthU cliffNestU gorgonLairU altarOfEarthU',
    'horde5 dendroidSaplings',
    'dwelling6 trainingGrounds unicornGlade goldenPavilion fireLake hallOfDarkness manticoreLair cyclopCave wyvernNest altarOfThought',
    'dwelling6U trainingGroundsU unicornGladeU goldenPavilionU fireLakeU hallOfDarknessU manticoreLairU cyclopCaveU wyvernNestU altarOfThoughtU',
    'dwelling7 portalOfGlory dragonCliffs cloudTemple forsakenPalace dragonVault dragonCave behemothLair hydraPond pyre',
    // Byte 6
    'dwelling7U portalOfGloryU dragonCliffsU cloudTempleU forsakenPalaceU dragonVaultU dragonCaveU behemothLairU hydraPondU pyreU',
  ];

  static $disabledBuildingss = 'HeroWO\\H3M\\ObjectDetails_Town::$builts';
  static $randomTypes = 'HeroWO\\H3M\\H3M::$playerss';

  public $objectID; // int
  public $owner; // null if unowned, str if known, else int
  public $name; // str, null
  public $creatures; // hash of Creature, null if have none
  public $formation; // str if known, else int; on write, null = spread (0)
  public $built; // true if hasFort, false if doesn't, else array of 'grail colossus ...' and/or int for unknown
  public $disabledBuildings = []; // array (in $built format), empty if $built is bool
  public $existingSpells = []; // array of enabled SPTRAITS.TXT index
  public $impossibleSpells; // array of enabled SPTRAITS.TXT index; on write, null = []
  public $events = []; // array of TownEvent
  public $randomType; // null if regular town, null if random and, after game starts, must equal $owner or be randomized if unowned, str if random and must match that player's alignment, else int if unknown player

  public $type; // null if random town, str 'castle', int if unknown; determined by MapObject->$subclass
  public $visiting; // int index of visiting hero, null none; set by ObjectDetails_Hero; on write, only setting ObjectDetails_Hero->$visiting is enough

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    $cx->isOrUp('AB') and $this->unpack($cx, 'V objectID');
    $this->unpack($cx, 'C owner', ['owner' => ['map' => [0xFF => null]]]);

    if ($this->unpack($cx, 'b hasName', false)[0]) {
      $this->readString($cx, 'name');
    }

    if ($this->unpack($cx, 'b hasCreatures', false)[0]) {
      $this->creatures = Creature::readAllFromUncompressedH3M(7, $cx);
    }

    $this->unpack($cx, 'C formation');

    if ($this->unpack($cx, 'b hasBuildings', false)[0]) {
      $this->unpack($cx, 'Bk6 built/Bk6 disabledBuildings');
    } else {
      list($this->built) = $this->unpack($cx, 'b hasFort', false);
    }

    if ($cx->isOrUp('AB')) {
      $this->unpack($cx, 'Bk9 existingSpells');
    }

    $this->unpack($cx, 'Bk9 impossibleSpells');

    list($count) = $this->unpack($cx, 'V eventCount', false);
    while ($count--) {
      ($this->events[] = new TownEvent)->readUncompressedH3M($cx);
    }

    if ($cx->isOrUp('SoD')) {
      $this->unpack($cx, 'C randomType', ['randomType' => ['map' => [0xFF => null]]]);
    }

    $this->unpack($cx, 'z 3');

    if ($options['options']->class === 98) {
      $this->type = $cx->enum(Player::$townss, $options['options']->subclass, '$type');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);

    $cx->isOrUp('AB') and $this->pack($cx, 'V objectID');
    $this->pack($cx, 'C owner', ['owner' => ['map' => [0xFF => null]]]);

    $this->pack($cx, 'b hasName', strlen($this->name) > 0);
    strlen($this->name) and $this->writeString($cx, 'name');

    $this->pack($cx, 'b hasCreatures', (bool) $this->creatures);
    if ($this->creatures) {
      Creature::writeAllToUncompressedH3M($this->creatures, $cx, ['count' => 7]);
    }

    $this->pack($cx, 'C? formation');
    $this->pack($cx, 'b hasBuildings', !is_bool($this->built));

    if (!is_bool($this->built)) {
      $this->pack($cx, 'Bk6 built/Bk6 disabledBuildings');
    } else {
      $this->pack($cx, 'b hasFort', $this->built);
      if ($this->disabledBuildings) {
        $cx->warning('$%s: non-empty while %s is non-array', 'disabledBuildings', 'built');
      }
    }

    if ($cx->isOrUp('AB')) {
      $this->pack($cx, 'Bk9 existingSpells');
    } elseif ($this->existingSpells) {
      $cx->warning('$%s: supported in AB+', 'existingSpells');
    }

    $this->pack($cx, 'Bk9? impossibleSpells');

    $this->pack($cx, 'V eventCount', count($this->events));
    foreach ($this->events as $event) {
      $event->writeUncompressedH3M($cx);
    }

    if ($cx->isOrUp('SoD')) {
      $this->pack($cx, 'C randomType', ['randomType' => ['map' => [0xFF => null]]]);
    } elseif (isset($this->randomType)) {
      $cx->warning('$%s: supported in SoD+', 'randomType');
    }
    $this->pack($cx, 'z 3');

    if ($options['parent']->class === 98 and isset($this->type)) {
      $cx->packEnumCompare(Player::$townss, $this, 'type', $options['parent'], 'subclass');
    }

    if (isset($this->visiting) and
        $options['parent']->index !== $s = $cx->h3m->objects[$this->visiting]->details->visiting) {
      $cx->warning('$visiting (%d) of town mismatches $visiting of hero (%d)', $this->visiting, $s);
    }
  }
}
Structure::$factories['objectDetails']['town'] = [
  'if' => function (object $options) {
    return in_array($options->class, [98, 77]);
  },
  'class' => ObjectDetails_Town::class,
];

class Event extends Structure {
  static $playerss = 'HeroWO\\H3M\\H3M::$playerss';
  static $builds = 'HeroWO\\H3M\\ObjectDetails_Town::$builts';

  public $name; // str
  public $message; // str
  public $resources; // Resources (can be negative); on write, null = all 0
  public $players; // array of enabled 'red'; on write, null = all
  public $applyToHuman = true; // bool
  public $applyToComputer; // bool
  public $firstDay; // int 0-based
  public $repeatDay; // int 1+, null to not repeat

  protected function readH3M(Context $cx, array $options) {
    $this->readString($cx, 'name');
    $this->readString($cx, 'message');
    ($this->resources = new Resources)->readUncompressedH3M($cx, ['size' => 'S']);
    $this->unpack($cx, 'Bk1 players');
    $cx->isOrUp('SoD') and $this->unpack($cx, 'b applyToHuman');
    $this->unpack($cx, 'b applyToComputer/v firstDay/C repeatDay/z 17');

    $this->repeatDay or $this->repeatDay = null;
    $this->checkFlags($cx);
  }

  protected function checkFlags(Context $cx) {
    if ($this->players === []) {
      $cx->warning('$%s: none enabled', 'players');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    $this->checkFlags($cx);
    $this->writeString($cx, 'name');
    $this->writeString($cx, 'message');
    ($this->resources ?: new Resources)->writeUncompressedH3M($cx, ['size' => 'S', 'negative' => true]);
    $this->pack($cx, 'Bk1 players', ['players' => ['value' =>
      isset($this->players) ? $this->players : H3M::$playerss]]);
    if ($cx->isOrUp('SoD')) {
      $this->pack($cx, 'b applyToHuman');
    } elseif (!$this->applyToHuman) {
      $cx->warning('$%s: supported in SoD+', 'applyToHuman');
    }
    if ($this->repeatDay === 0) {
      $cx->warning('$%s: 0 taken as "no repeat"', 'repeatDay');
    }
    $this->pack($cx, 'b applyToComputer/v firstDay/C? repeatDay/z 17');
  }
}

class TownEvent extends Event {
  static $builds = 'HeroWO\\H3M\\ObjectDetails_Town::$builts';

  public $build; // array in ObjectDetails_Town->$built format; on write, null = []
  public $growth; // hash of dwelling level (0-6) => int non-0 bonus; on write, null = all 0

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'Bk6 build');
    $this->growth = new Hash(array_filter($this->unpack($cx,
      'v growth1/v growth2/v growth3/v growth4/v growth5/v growth6/v growth7', false)));
    $this->unpack($cx, 'z 4');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'Bk6? build');
    $growth = $this->growth;
    for ($i = 0; $i < 7; $i++) {
      $this->pack($cx, 'v growth'.($i + 1), isset($growth[$i]) ? $growth[$i] : 0);
    }
    if ($growth and $growth->nextKey() > $i) {
      $pack->warning('$%s: extraneous members', 'growth');
    }
    $this->pack($cx, 'z 4');
  }
}

class ObjectDetails_RandomDwelling extends ObjectDetails {
  static $owners = 'HeroWO\\H3M\\H3M::$playerss';
  static $townss = 'HeroWO\\H3M\\Player::$townss';

  // HoMM 3 has three groups of random dwelling objects: fully random, random
  // with a level and random with a town type. H3M provides these properties:
  //     | type | $owner | $objectID | $towns | $minLevel/$maxLevel
  // 216 | FR   | yes    | exactly one is set | yes
  // 217 | RL   | yes    | exactly one is set | no
  // 218 | RT   | yes    | no                 | yes
  //
  // This class autofills $towns (for RT) and $min/maxLevel (for RL) to reduce
  // branching on the client's side. As a result, $min/maxLevel are always set
  // and one of $objectID/$towns is always set.
  //
  // If needed, exact object type can be inferred from MapObject->$class.
  public $owner; // null if unowned, str if known, else int
  public $objectID; // null if $towns set, int
  public $towns; // null if $objectID set, array of 'rampart' and/or int for unknown towns; on write, if both are null then $towns = all (FR/RL; unused by RT)
  public $minLevel; // int 0-based
  public $maxLevel; // int 0-based

  public $object; // int town index in H3M->$objects, null if none or not found; on write, provides $objectID if unset

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    $class = $options['options']->class;
    $this->unpack($cx, 'V owner', ['owner' => ['map' => [0xFF => null]]]);

    if (in_array($class, [216, 217])) {
      list($id) = $this->unpack($cx, 'V objectID', false);
      $id ? $this->objectID = $id : $this->unpack($cx, 'Bk2 towns');
    } else {
      $this->towns = [$cx->enum(Player::$townss, $options['options']->subclass, '$towns')];
    }

    if (in_array($class, [216, 218])) {
      $this->unpack($cx, 'C minLevel/C maxLevel');
    } else {
      $this->minLevel = $this->maxLevel = $options['options']->subclass;
    }

    $this->checkFlags($cx);
  }

  protected function checkFlags(Context $cx) {
    if ($this->towns === []) {
      $cx->warning('$%s: none enabled', 'towns');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->checkFlags($cx);

    $class = $options['parent']->class;
    $this->pack($cx, 'V owner', ['owner' => ['map' => [0xFF => null]]]);

    if (in_array($class, [216, 217])) {
      $this->packReferencedObject($cx, [], ['objectID']);
      if (isset($this->object) and
          get_class($cx->h3m->objects[$this->object]->details) !== ObjectDetails_Town::class) {
        $cx->warning('$%s: must be a town', 'object');
      }
      $this->pack($cx, 'V? objectID');
      if (!$this->objectID) {
        $this->pack($cx, 'Bk2 towns', isset($this->towns) ? $this->towns
          : Player::$townss);
      } elseif (isset($this->towns)) {
        $cx->warning('non-null $towns with set $objectID');
      }
    } else {
      if ($this->objectID or isset($this->object)) {
        $cx->warning('$objectID: incompatible with %d', $class);
      }
      if (isset($this->towns) and
          $cx->packEnumArray(Player::$townss, $this->towns, '$towns') !== [$s = $cx->enum(Player::$townss, $options['parent']->subclass, '$subclass')]) {
        $cx->warning('$towns: mismatches [%d]', $s);
      }
    }

    if (in_array($class, [216, 218])) {
      $this->pack($cx, 'C minLevel/C maxLevel');
    } elseif ((isset($this->minLevel) and $this->minLevel !== $options['parent']->subclass) or
              (isset($this->maxLevel) and $this->maxLevel !== $options['parent']->subclass)) {
      $cx->warning('$minLevel/$maxLevel of %d mismatch %d',
        $class, $options['parent']->subclass);
    }
  }
}
Structure::$factories['objectDetails']['randomDwelling'] = [
  'if' => function (object $options) {
    return in_array($options->class, [216, 218, 217]);
  },
  'class' => ObjectDetails_RandomDwelling::class,
];

class ObjectDetails_Hero extends ObjectDetails {
  static $owners = 'HeroWO\\H3M\\H3M::$playerss';
  static $formations = 'HeroWO\\H3M\\ObjectDetails_Town::$formations';
  static $genders = 'HeroWO\\H3M\\Hero::$genders';

  public $objectID; // int
  public $owner; // null for Prison object (62), str if known, else int
  public $type; // HOTRAITS.TXT index, null for random hero (70)
  public $name; // str, null for default
  public $experience; // int, null for default
  public $face; // HOTRAITS.TXT index, null for default
  public $skills; // array of Skill, null for default
  public $creatures; // hash of slot => Creature, null
  public $formation; // str if known, else int; on write, null = spread (0)
  public $artifacts; // as Hero->$artifacts
  public $patrolRadius; // int, null if may wander anywhere
  public $biography; // str, empty str if so defined, null for default
  public $gender; // str if known, else int, null for default
  public $spells; // array of enabled SPTRAITS.TXT index, null for default
  public $attack;     // int, null for default (then other 3 are also null)
  public $defense;    // int, null for default (then other 3 are also null)
  public $spellPower; // int, null for default (then other 3 are also null)
  public $knowledge;  // int, null for default (then other 3 are also null)

  // HoMM 3 positions visiting hero at the town's coords (i.e. their bottom
  // right corners are aligned).
  public $visiting; // int index of town this hero is visiting, null none

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    $cx->isOrUp('AB') and $this->unpack($cx, 'V objectID');

    if ($options['options']->class !== 62) {
      $this->unpack($cx, 'C owner');
    } elseif ($owner = ord($cx->stream->read(1)) !== 0xFF) {
      $cx->warning('Prison object has $owner = %d', $owner);
    }

    $this->unpack($cx, 'C type');
    if ($options['options']->class === 70) {
      if ($this->type !== 0xFF) {
        $cx->warning('Random hero object has $type = %d', $this->type);
      }
      $this->type = null;
    }

    if ($this->unpack($cx, 'b hasName', false)[0]) {
      $this->readString($cx, 'name');
    }

    if (!$cx->isOrUp('SoD') or $this->unpack($cx, 'b hasExperience', false)[0]) {
      $this->unpack($cx, 'V experience');
    }

    if ($this->unpack($cx, 'b hasFace', false)[0]) {
      $this->unpack($cx, 'C face');
    }

    if ($this->unpack($cx, 'b hasSkills', false)[0]) {
      $this->skills = Skill::readAllFromUncompressedH3M($cx);
    }

    if ($this->unpack($cx, 'b hasCreatures', false)[0]) {
      $this->creatures = Creature::readAllFromUncompressedH3M(7, $cx);
    }

    $this->unpack($cx, 'C formation');

    if ($this->unpack($cx, 'b hasArtifacts', false)[0]) {
      $this->artifacts = Artifact::readAllFromUncompressedH3M($cx, [
        'size' => $cx->isOrUp('AB') ? 'v' : 'C',
        'default' => $cx->isOrUp('AB') ? 0xFFFF : 0xFF,
        'catapult' => 'SoD',
      ]);
    }

    $this->unpack($cx, 'C patrolRadius');
    $this->patrolRadius === 0xFF and $this->patrolRadius = null;

    if ($cx->isOrUp('AB') and $this->unpack($cx, 'b hasBiography', false)[0]) {
      $this->readString($cx, 'biography');
      strlen($this->biography) or $this->biography = '';
    }

    if ($cx->isOrUp('AB')) {
      $this->unpack($cx, 'C gender', ['gender' => ['map' => [0xFF => null]]]);
    }

    if ($cx->is('AB')) {
      $this->spells = [$this->unpack($cx, 'C spells', false)];
    } elseif ($cx->isOrUp('SoD') and $this->unpack($cx, 'b hasSpells', false)[0]) {
      $this->unpack($cx, 'Bk9 spells');
    }

    if ($cx->isOrUp('SoD') and $this->unpack($cx, 'b hasPrimarySkills', false)[0]) {
      $this->unpack($cx, 'C attack/C defense/C spellPower/C knowledge');
    }

    $this->unpack($cx, 'z 16');
    $this->checkFlags($cx);
  }

  protected function checkFlags(Context $cx) {
    if ($this->skills === []) {
      $cx->warning('$%s: none enabled', 'skills');
    }

    if ($this->creatures and !count($this->creatures)) {
      $cx->warning('$%s: none enabled', 'creatures');
    }
  }

  function resolveH3M(Context $cx, $object = null) {
    parent::resolveH3M($cx, $object);

    $obj = $cx->h3m->objects[$object];
    $ref = &$cx->objectIndex["town.$obj->x.$obj->y.$obj->z"];
    if (isset($ref)) {
      $this->visiting = $ref;
      $cx->h3m->objects[$ref]->details->visiting = $object;
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->checkFlags($cx);

    $cx->isOrUp('AB') and $this->pack($cx, 'V objectID');

    if ($options['parent']->class !== 62) {
      $this->pack($cx, 'C owner');
    } else {
      isset($this->owner) and $cx->warning('Prison object has $owner');
      $cx->stream->write("\xFF");
    }

    $type = $this->packDefault($cx, 'type', 0xFF);
    if ($options['parent']->class === 70 and $type !== 0xFF) {
      $cx->warning('Random hero object must have no $type');
      $type = 0xFF;
    }
    $this->pack($cx, 'C type', $type);

    $this->pack($cx, 'b hasName', strlen($this->name) > 0);
    strlen($this->name) and $this->writeString($cx, 'name');

    if (!$cx->isOrUp('SoD')) {
      isset($this->experience) or $cx->warning('$%s: supported in SoD+', 'experience');
      $this->pack($cx, 'V? experience');
    } else {
      $this->pack($cx, 'b hasExperience', isset($this->experience));
      isset($this->experience) and $this->pack($cx, 'V experience');
    }

    $this->pack($cx, 'b hasFace', isset($this->face));
    isset($this->face) and $this->pack($cx, 'C face');

    $this->pack($cx, 'b hasSkills', isset($this->skills));
    isset($this->skills) and Skill::writeAllToUncompressedH3M($cx, $this->skills);

    $this->pack($cx, 'b hasCreatures', (bool) $this->creatures);
    if ($this->creatures) {
      Creature::writeAllToUncompressedH3M($this->creatures, $cx, ['count' => 7]);
    }

    $this->pack($cx, 'C? formation');

    $this->pack($cx, 'b hasArtifacts', isset($this->artifacts));
    if (isset($this->artifacts)) {
      Artifact::writeAllToUncompressedH3M($cx, $this->artifacts, [
        'size' => $cx->isOrUp('AB') ? 'v' : 'C',
        'default' => $cx->isOrUp('AB') ? 0xFFFF : 0xFF,
        'catapult' => 'SoD',
      ]);
    }

    $this->pack($cx, 'C patrolRadius', $this->packDefault($cx, 'patrolRadius', 0xFF));

    if ($cx->isOrUp('AB')) {
      $this->pack($cx, 'b hasBiography', isset($this->biography));
      isset($this->biography) and $this->writeString($cx, 'biography');
    } elseif (isset($this->biography)) {
      $cx->warning('$%s: supported in AB+', 'biography');
    }

    if ($cx->isOrUp('AB')) {
      $this->pack($cx, 'C gender', ['gender' => ['map' => [0xFF => null]]]);
    } elseif (isset($this->gender)) {
      $cx->warning('$%s: supported in AB+', 'gender');
    }

    if ($cx->is('AB')) {
      $count = count($this->spells ?: []);
      $count === 1 or $cx->warning('$%s: supported in SoD+', 'spells');
      $spell = $count ? reset($this->spells) : 15 /*Magic Arrow*/;
      $this->pack($cx, 'C spells', $spell);
    } elseif ($cx->isOrUp('SoD')) {
      $this->pack($cx, 'b hasSpells', isset($this->spells));
      isset($this->spells) and $this->pack($cx, 'Bk9 spells');
    } elseif (isset($this->spells)) {
      $cx->warning('$%s: supported in AB/SoD+', 'spells');
    }

    $set = isset($this->attack) || isset($this->defense) ||
           isset($this->spellPower) || isset($this->knowledge);
    if ($cx->isOrUp('SoD')) {
      $this->pack($cx, 'b hasPrimarySkills', $set);
      $set and $this->pack($cx, 'C attack/C defense/C spellPower/C knowledge');
    } elseif ($set) {
      $cx->warning('$%s: supported in SoD+', 'attack/defense/spellPower/knowledge');
    }

    $this->pack($cx, 'z 16');
  }
}
Structure::$factories['objectDetails']['hero'] = [
  'if' => function (object $options) {
    return in_array($options->class, [34, 70, 62]);
  },
  'class' => ObjectDetails_Hero::class,
];

class ObjectDetails_Monster extends ObjectDetails {
  static $dispositions = ['compliant', 'friendly', 'aggressive', 'hostile',
                          'savage'];
  static $levels = [72, 73, 74, 75, 162, 163, 164];

  public $objectID; // int
  public $count; // int 1+, null for random
  public $disposition; // str if known, else int; on write, null = aggressive (2)
  public $message; // str, null
  public $resources; // Resources (can't be negative), null
  public $artifact; // ARTRAITS.TXT index, null
  public $canFlee; // bool; on write, null = true
  public $canGrow; // bool; on write, null = true

  // These are determined by MapObject->$class.
  public $creature; // CRTRAITS.txt index, null if random
  public $level; // int 0-based creature level, null if any (fully random; 71) or specific non-random monster (54)

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    $cx->isOrUp('AB') and $this->unpack($cx, 'V objectID');

    $this->unpack($cx, 'v count/C disposition');
    $this->count or $this->count = null;

    if ($this->unpack($cx, 'b hasTreasure', false)[0]) {
      $this->readString($cx, 'message');
      ($this->resources = new Resources)->readUncompressedH3M($cx);
      list($art) = $this->unpack($cx, ($cx->isOrUp('AB') ? 'v' : 'C').' artifact');
      $art === ($cx->isOrUp('AB') ? 0xFFFF : 0xFF) and $this->artifact = null;
    }

    $this->unpack($cx, 'b canFlee/b canGrow/z 2');
    $this->canFlee = !$this->canFlee;
    $this->canGrow = !$this->canGrow;

    if ($options['options']->class === 54) {
      $this->creature = $options['options']->subclass;
    } elseif ($options['options']->class !== 71) {
      $this->level = array_search($options['options']->class, static::$levels);
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);

    $cx->isOrUp('AB') and $this->pack($cx, 'V objectID');

    if ($this->count === 0) {
      $cx->warning('$%s: 0 taken as "random"', 'count');
    }
    $this->pack($cx, 'v? count');
    $this->pack($cx, 'C disposition', isset($this->disposition)
      ? $this->disposition : 2);

    $has = isset($this->message) || isset($this->resources) || isset($this->artifact);
    $this->pack($cx, 'b hasTreasure', $has);
    if ($has) {
      $this->writeString($cx, 'message');
      ($this->resources ?: new Resources)->writeUncompressedH3M($cx);
      $this->pack($cx, ($cx->isOrUp('AB') ? 'v' : 'C').' artifact',
                  $this->packDefault($cx, 'artifact', $cx->isOrUp('AB') ? 0xFFFF : 0xFF));
    }

    $this->pack($cx, 'b canFlee', !(isset($this->canFlee) ? $this->canFlee : true));
    $this->pack($cx, 'b canGrow', !(isset($this->canGrow) ? $this->canGrow : true));
    $this->pack($cx, 'z 2');

    if ($options['parent']->class <= 71 /*or 54*/ and isset($this->level)) {
      $cx->warning('$level: incompatible with %d', $options['parent']->class);
    }
    if ($options['parent']->class === 54) {
      if (isset($this->creature) and $this->creature !== $options['parent']->subclass) {
        $cx->warning('$creature: mismatches %d', $options['parent']->subclass);
      }
    } elseif ($options['parent']->class !== 71 and isset($this->level) and
              $this->level !== array_search($options['parent']->class, static::$levels)) {
      $cx->warning('$level: mismatches %d', $options['parent']->class);
    }
  }
}
Structure::$factories['objectDetails']['monster'] = [
  'if' => function (object $options) {
    return in_array($options->class, [54, 71, 72, 73, 74, 75, 162, 163, 164]);
  },
  'class' => ObjectDetails_Monster::class,
];

abstract class GuardedObjectDetails extends ObjectDetails {
  public $guard; // Guarded, null

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    if ($this->unpack($cx, 'b isGuarded', false)[0]) {
      ($this->guard = new Guarded)->readUncompressedH3M($cx);
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);

    $this->pack($cx, 'b isGuarded', (bool) $this->guard);
    $this->guard and $this->guard->writeUncompressedH3M($cx);
  }
}

// Message and guards: show message alone, with ok/cancel buttons, combat on ok.
// Message only: show message together with the artifact's icon, ok button.
// Guards only: show generic message ("...guarded by few Pikemen..."), ok/no.
// None: show artifact-specific message with the icon, ok button.
class ObjectDetails_Artifact extends GuardedObjectDetails {
  public $artifact; // ARTRAITS.TXT index, determined by MapObject->$class, null random

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    if ($options['options']->class === 5) {
      $this->artifact = $options['options']->subclass;
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);

    if (isset($this->artifact)) {
      if ($options['parent']->class !== 5) {
        $cx->warning('$artifact: incompatible with %d', $options['parent']->class);
      } elseif ($this->artifact !== $options['parent']->subclass) {
        $cx->warning('$artifact: mismatches %d', $options['parent']->subclass);
      }
    }
  }
}
Structure::$factories['objectDetails']['artifact'] = [
  'if' => function (object $options) {
    return in_array($options->class, [5, 65, 66, 67, 68, 69]);
  },
  'class' => ObjectDetails_Artifact::class,
];

class ObjectDetails_Shrine extends ObjectDetails {
  public $spell; // SPTRAITS.TXT index, null random

  public $level; // int 0-based spell level, determined by MapObject->$class

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V spell');
    $this->spell === 0xFF and $this->spell = null;
    $this->level = $options['options']->class - 88;
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'V spell', $this->packDefault($cx, 'spell', 0xFF));
    if (isset($this->level) and $this->level !== $options['parent']->class - 88) {
      $cx->warning('$level: mismatches %d', $options['parent']->class);
    }
  }
}
Structure::$factories['objectDetails']['shrine'] = [
  'if' => function (object $options) {
    return in_array($options->class, [88, 89, 90]);
  },
  'class' => ObjectDetails_Shrine::class,
];

// Message and guards: show message alone, with ok/cancel buttons, combat on ok.
// Message only: show message together with the spell's icon on scroll, ok button.
// Guards only: immediately start combat.
// None: show generic message ("...you find...") with the icon, ok button.
class ObjectDetails_SpellScroll extends GuardedObjectDetails {
  public $spell; // SPTRAITS.TXT index

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V spell');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'V spell');
  }
}
Structure::$factories['objectDetails']['scroll'] = [
  'if' => function (object $options) { return $options->class === 93; },
  'class' => ObjectDetails_SpellScroll::class,
];

// Message and guards: show message alone, with ok/cancel buttons, combat on ok.
// Message only: show message without any icons, ok button.
// Guards only: immediately start combat.
// None: show generic message in right-side panel with the resource icon.
class ObjectDetails_Resource extends GuardedObjectDetails {
  // If this is set but $resource is not (= Random Resource), if the type
  // determined on run-time is gold then $quantity should be multiplied by 100.
  public $quantity; // int, null random

  public $resource; // null random resource, str if known, else int; determined by MapObject->$subclass

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V quantity/z 4');

    if ($options['options']->class !== 76) {
      $this->resource = $cx->enum(VictoryCondition_AccumulateResources::$resources,
                                  $options['options']->subclass, '$resource');
      $this->resource === 'gold' and $this->quantity *= 100;
    }

    $this->quantity === 0 and $this->quantity = null;
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $quantity = $this->quantity;

    if ($quantity === 0) {
      $cx->warning('$%s: 0 taken as "random"', 'quantity');
    }

    if (isset($this->resource)) {
      if ($options['parent']->class === 76) {
        $cx->warning('$%s: incompatible with %d', 'resource',
          $options['parent']->class);
      } else {
        $cx->packEnumCompare(VictoryCondition_AccumulateResources::$resources,
                             $this, 'resource', $options['parent'], 'subclass');
      }
    }

    if ($options['parent']->class !== 76 and
        $cx->enum(VictoryCondition_AccumulateResources::$resources, $options['parent']->subclass, '$resource') === 'gold') {
      $quantity /= 100;
      fmod($quantity, 1) and $cx->warning('$%s: rounded down', 'quantity');
    }

    $this->pack($cx, 'V? quantity', $quantity);
    $this->pack($cx, 'z 4');
  }
}
Structure::$factories['objectDetails']['resource'] = [
  'if' => function (object $options) {
    return in_array($options->class, [79, 76]);
  },
  'class' => ObjectDetails_Resource::class,
];

class ObjectDetails_WitchHut extends ObjectDetails {
  public $potentialSkills; // array of enabled SSTRAITS.TXT index, null for all but Leadership and Necromancy enabled

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $cx->isOrUp('AB') and $this->unpack($cx, 'Bk4 potentialSkills');
    $this->checkFlags($cx);
  }

  protected function checkFlags(Context $cx) {
    if ($this->potentialSkills === []) {
      $cx->warning('$%s: none enabled', 'potentialSkills');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->checkFlags($cx);
    $default = array_diff(range(0, 27), [6, 12]);
    if ($cx->isOrUp('AB')) {
      $v = isset($this->potentialSkills) ? $this->potentialSkills : $default;
      $this->pack($cx, 'Bk4 potentialSkills', ['potentialSkills' => ['value' => $v]]);
    } elseif (isset($this->potentialSkills) and
              !sameMembers($this->potentialSkills, $default)) {
      $cx->warning('$%s: supported in AB+', 'potentialSkills');
    }
  }
}
Structure::$factories['objectDetails']['witchHut'] = [
  'if' => function (object $options) { return $options->class === 113; },
  'class' => ObjectDetails_WitchHut::class,
];

class ObjectDetails_SeerHut extends ObjectDetails_QuestGuard {
  public $reward; // Reward, null if none

  protected function readH3M(Context $cx, array $options) {
    if ($cx->isOrUp('AB')) {
      parent::readH3M($cx, $options);
    } else {
      $quest = new Quest_Artifacts;
      $quest->artifacts[] = $artifact = new Artifact;
      $artifact->unpack($cx, 'C artifact');

      if ($artifact->artifact !== 0xFF) {
        $this->quest = $quest;
      }
    }

    list($type) = $this->unpack($cx, 'C rewardType', false);
    $type and $this->factory($cx, 'reward', 'reward', compact('type'));

    $this->unpack($cx, 'z 2');
  }

  protected function writeH3M(Context $cx, array $options) {
    if ($cx->isOrUp('AB')) {
      parent::writeH3M($cx, $options);
    } else {
      if ($this->quest) {
        if (get_class($this->quest) === Quest_Artifacts::class) {
          $artifacts = $this->quest->artifacts;
        } else {
          $cx->warning('$%s: supported in AB+', 'quest');
        }
      }
      if (empty($artifacts)) {
        $art = 0xFF;
      } else {
        count($artifacts) > 1 and $cx->warning('$%s: supported in AB+', 'quest');
        $art = reset($artifacts);
      }
      $this->pack($cx, 'C artifact', $art);
    }

    $this->packFactory($cx, 'reward', 'reward', "\0");
    $this->pack($cx, 'z 2');
  }
}
Structure::$factories['objectDetails']['seerHut'] = [
  'if' => function (object $options) { return $options->class === 83; },
  'class' => ObjectDetails_SeerHut::class,
];

abstract class Reward extends StructureRW {
  use FactoryStructure { FactoryStructure_writeH3M as writeH3M; }
}

class Reward_Experience extends Reward {
  const TYPE = 1;

  public $experience; // int 1+

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V experience');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if ($this->experience < 1) {
      $cx->warning('non-positive Reward_Experience->$experience');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'V experience');
  }
}
Structure::$factories['reward']['experience'] = [
  'if' => function (object $options) { return $options->type === Reward_Experience::TYPE; },
  'class' => Reward_Experience::class,
];

class Reward_SpellPoints extends Reward {
  const TYPE = 2;

  public $spellPoints; // int 1+

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'V spellPoints');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if ($this->spellPoints < 1) {
      $cx->warning('non-positive Reward_SpellPoints->$spellPoints');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'V spellPoints');
  }
}
Structure::$factories['reward']['spellPoints'] = [
  'if' => function (object $options) { return $options->type === Reward_SpellPoints::TYPE; },
  'class' => Reward_SpellPoints::class,
];

class Reward_Morale extends Reward {
  const TYPE = 3;

  public $morale; // int 0..2 (+1)

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C morale');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if ($this->morale > 2) {
      $cx->warning('$%s: out of bounds', 'morale');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C morale');
  }
}
Structure::$factories['reward']['morale'] = [
  'if' => function (object $options) { return $options->type === Reward_Morale::TYPE; },
  'class' => Reward_Morale::class,
];

class Reward_Luck extends Reward {
  const TYPE = 4;

  public $luck; // int 0..2 (+1)

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C luck');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if ($this->luck > 2) {
      $cx->warning('$%s: out of bounds', 'luck');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C luck');
  }
}
Structure::$factories['reward']['luck'] = [
  'if' => function (object $options) { return $options->type === Reward_Luck::TYPE; },
  'class' => Reward_Luck::class,
];

class Reward_Resource extends Reward {
  const TYPE = 5;

  static $resources = 'HeroWO\\H3M\\VictoryCondition_AccumulateResources::$resources';

  public $resource; // str if known, else int
  public $quantity; // int 1+

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C resource/V quantity');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if ($this->quantity < 1) {
      $cx->warning('non-positive Reward_Resource->$quantity');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C resource/V quantity');
  }
}
Structure::$factories['reward']['resource'] = [
  'if' => function (object $options) { return $options->type === Reward_Resource::TYPE; },
  'class' => Reward_Resource::class,
];

class Reward_PrimarySkill extends Reward {
  const TYPE = 6;

  static $skills = ['attack', 'defense', 'spellPower', 'knowledge'];

  public $skill; // str if known, else int
  public $change; // int 1+

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C skill/C change');
  }

  protected function checkFlags(Context $cx) {
    parent::checkFlags($cx);
    if ($this->change < 1) {
      $cx->warning('non-positive Reward_PrimarySkill->$change');
    }
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C skill/C change');
  }
}
Structure::$factories['reward']['primarySkill'] = [
  'if' => function (object $options) { return $options->type === Reward_PrimarySkill::TYPE; },
  'class' => Reward_PrimarySkill::class,
];

class Reward_Skill extends Reward {
  const TYPE = 7;

  public $skill; // Skill

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    ($this->skill = new Skill)->readUncompressedH3M($cx);
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->skill->writeUncompressedH3M($cx);
  }
}
Structure::$factories['reward']['skill'] = [
  'if' => function (object $options) { return $options->type === Reward_Skill::TYPE; },
  'class' => Reward_Skill::class,
];

class Reward_Artifact extends Reward {
  const TYPE = 8;

  public $artifact; // Artifact

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    ($this->artifact = new Artifact)->readUncompressedH3M($cx, [
      'size' => $cx->isOrUp('AB') ? 'v' : 'C',
    ]);
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->artifact->writeUncompressedH3M($cx, [
      'size' => $cx->isOrUp('AB') ? 'v' : 'C',
    ]);
  }
}
Structure::$factories['reward']['artifact'] = [
  'if' => function (object $options) { return $options->type === Reward_Artifact::TYPE; },
  'class' => Reward_Artifact::class,
];

class Reward_Spell extends Reward {
  const TYPE = 9;

  public $spell; // SPTRAITS.TXT index

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C spell');
  }

  protected function FactoryStructure_writeH3M(Context $cx, array $options) {
    $this->checkFlags($cx);
    $this->pack($cx, 'C type', ($options + ['forceType' => static::TYPE])['forceType']);
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C spell');
  }
}
Structure::$factories['reward']['spell'] = [
  'if' => function (object $options) { return $options->type === Reward_Spell::TYPE; },
  'class' => Reward_Spell::class,
];

class Reward_Creature extends Reward {
  const TYPE = 10;

  public $creature; // Creature

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    ($this->creature = new Creature)->readUncompressedH3M($cx);
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->creature->writeUncompressedH3M($cx);
  }
}
Structure::$factories['reward']['creature'] = [
  'if' => function (object $options) { return $options->type === Reward_Creature::TYPE; },
  'class' => Reward_Creature::class,
];

class ObjectDetails_Scholar extends ObjectDetails {
  public $reward; // Reward, null if random

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);

    list($type) = $this->unpack($cx, 'C rewardType', false);
    if ($type === 0xFF) {
      $this->unpack($cx, 'z 1');
    } else {
      $this->factory($cx, 'reward', 'scholarReward', compact('type'));
    }

    $this->unpack($cx, 'z 6');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->packFactory($cx, 'reward', 'scholarReward', "\xFF\0", ['forceType' => 2]);
    $this->pack($cx, 'z 6');
  }
}
Structure::$factories['objectDetails']['scholar'] = [
  'if' => function (object $options) { return $options->class === 81; },
  'class' => ObjectDetails_Scholar::class,
];

class Reward_ScholarPrimarySkill extends Reward {
  const TYPE = 0;

  static $skills = 'HeroWO\\H3M\\Reward_PrimarySkill::$skills';

  public $skill; // str if known, else int

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C skill');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C skill');
  }
}
Structure::$factories['scholarReward']['primarySkill'] = [
  'if' => function (object $options) { return $options->type === Reward_ScholarPrimarySkill::TYPE; },
  'class' => Reward_ScholarPrimarySkill::class,
];

class Reward_ScholarSkill extends Reward {
  const TYPE = 1;

  public $skill; // SSTRAITS.TXT index

  protected function readH3M(Context $cx, array $options) {
    parent::readH3M($cx, $options);
    $this->unpack($cx, 'C skill');
  }

  protected function writeH3M(Context $cx, array $options) {
    parent::writeH3M($cx, $options);
    $this->pack($cx, 'C skill');
  }
}
Structure::$factories['scholarReward']['skill'] = [
  'if' => function (object $options) { return $options->type === Reward_ScholarSkill::TYPE; },
  'class' => Reward_ScholarSkill::class,
];

Structure::$factories['scholarReward']['spell'] = [
  'if' => function (object $options) { return $options->type === 2; },
  'class' => Reward_Spell::class,
];

// Returns a human-readable representation of a binary $s'ting. $offset is start
// number of the leftmost column, $shift is number of bytes missing from the
// first line (gap, < 16).
//
// Result never includes the final line break.
//
// Sample output ($offset = 0x1234, $shift = 3):
//
//   1234             78 79 7A 7B 7C 7D 7E 7F 80 81 82 83 84     xyz{|}~......
//   1244    85 86 87 88 89 8A 8B 8C                          ........
function hexDump($s, $offset = 0, $shift = 0) {
  $cols = 16;
  $nonPrintable = '.';

  $offsets = $hexes = $chars = [];
  $index = -1;

  while ($shift--) {
    $hexes[0][] = '  ';
    $chars[0][] = ' ';
  }

  foreach (str_split($s) as $char) {
    if (!$offsets or count($chars[$index]) >= $cols) {
      $offsets[++$index] = $offset;
      $offset += $cols;
    }

    $hexes[$index][] = sprintf('%02X', ord($char));
    $chars[$index][] = ord($char) < 32 || ord($char) > 126 ? $nonPrintable : $char;
  }

  while (count($chars[$index]) < $cols) {
    $hexes[$index][] = '  ';
    $chars[$index][] = ' ';
  }

  $padding = max(4, strlen(dechex($offset)));

  $lines = array_map(function ($o, array $h, array $c) use ($padding) {
    return sprintf("%0{$padding}X    %s  %s", $o, join(' ', $h), join($c));
  }, $offsets, $hexes, $chars);

  return join("\n", $lines);
}

// Returns true if both arrays have exactly the same members but possibly in
// different order. Duplicates in $b may produce unexpected behaviour.
function sameMembers(array $a, array $b) {
  return count(array_intersect($a, $b)) === count($b);
}

// Returns an array of values of public properties of $obj.
//
// get_object_vars() respects scope modifiers so calling it from within $obj
// will obtain private properties.
function publicProperties(object $obj) {
  return get_object_vars($obj);
}

// array_column() doesn't support objects in PHP 5.6.
if (version_compare(PHP_VERSION, '7.0.0') < 0) {
  function array_column(array $array, $key) {
    if ($array and is_object(reset($array))) {
      return array_map(function ($m) use ($key) { return $m->$key; }, $a);
    } else {
      return \array_column($array, $key);
    }
  }
}

// Returns true if $s represents a start of compressed H3M (Zlib/gzip) data,
// as opposed to uncompressed H3M stream (starting with a H3M::$formats magic);
//
// $s can be a resource. You may want to call rewind() afterwards.
function isCompressed($s) {
  is_resource($s) and $s = fread($s, 2);
  return !strncmp($s, "\x1F\x8B", 2);
}

// Implements commandline interface for this script. Can be used as a library
// class as well, instead of system('php h3m2json.php -...').
class CLI {
  // In MiB. Determined empirically while converting official and fan-made maps.
  static $memory_limit = 384;
  static $charsets = ['en' => 'cp1250', 'ru' => 'cp1251'];
  static $extensionToFormat = ['.h3m' => 'h3', '.json' => 'json',
                               '.dat' => 'php'];
  static $formatToExtension = ['h3' => 'h3m', 'h3u' => 'h3m', 'json' => 'json',
                               'php' => 'dat'];

  static $formatToTitle = [
    'h3' => '.h3m',
    'h3u' => 'uncompressed .h3m',
    'json' => '.json',
    'php' => 'PHP .dat',
  ];

  static $coverageIgnore = [
    'HeroWO\\H3M\\CLI->basename',
    'HeroWO\\H3M\\CLI->extensionToFormat',
    'HeroWO\\H3M\\CLI->checkCharset',
    'HeroWO\\H3M\\CLI->checkModule',
    'HeroWO\\H3M\\CLI->parseArgv',
    'HeroWO\\H3M\\CLI->handleException',
    'HeroWO\\H3M\\CLI->handleShutdown',
    'HeroWO\\H3M\\CLI->helpText',
    'HeroWO\\H3M\\CLI->mayTakeOver',
    'HeroWO\\H3M\\CLI->takeOver',
    'HeroWO\\H3M\\CLI->run',
    'HeroWO\\H3M\\CLI->process',
    'HeroWO\\H3M\\CLI->checkSkipConverted',
    'HeroWO\\H3M\\CLI->collectStatistics',
    'HeroWO\\H3M\\CLI->createContext',
    'HeroWO\\H3M\\CLI->printStatistics',
    'HeroWO\\H3M\\CLI->writeCoverage',
    'HeroWO\\H3M\\Context->printState',
    'HeroWO\\H3M\\Context->toJSON',
    'HeroWO\\H3M\\Context->toPHP',
    'HeroWO\\H3M\\Context->warning',
    'HeroWO\\H3M\\hexDump',
    'HeroWO\\H3M\\PhpStream->printState',
    'HeroWO\\H3M\\Structure->dump',
  ];

  public $outputStream = STDOUT;
  public $errorStream = STDERR;
  // See helpText() for details on these options.
  public $scriptFile = 'h3m2json.php';
  public $inputPath;
  public $inputFolder;
  public $inputFormat;
  public $outputFormat;
  public $outputPath;
  public $outputSubfolders = true;
  //= false to keep original $format but fully recompile even on H3M input/output
  public $outputMapFormat;
  public $skipConverted = false;
  public $selfTest = false;
  public $addMeta = false;
  public $charset = 'cp1250';
  public $partialParse = PHP_INT_MAX;
  public $dumpStructure = false;
  public $dumpMotions = false;
  public $dumpStatistics = false;
  public $watch;
  public $coveragePath;
  public $fullCoverage;

  public $ignoreParseError = false;
  public $ignoreBadInputFiles = false;
  public $failOnWarning = false;
  public $fullErrors = false;

  protected $lastContext;

  // Strips path components and extension.
  static function basename($path) {
    return preg_replace('~^.*[\\\\/]|\.[^\\\\/]*$~u', '', $path);
  }

  static function extensionToFormat($ext) {
    return isset(static::$extensionToFormat[$ext = strtolower($ext)])
      ? static::$extensionToFormat[$ext] : null;
  }

  static function checkCharset($charset) {
    static::checkModule('iconv', 'Processing H3M', 'iconv');

    try {
      if (iconv_strlen('a', $charset) === false) {
        throw new \Exception;
      }
      return;
    } catch (\Throwable $e) {
    } catch (\Exception $e) {}

    $msg = "Invalid string charset (-s $charset).";
    $msg .= ' Common values: EN (English - cp1250), RU (Russian - cp1251).';
    if (strncasecmp(PHP_OS, 'win', 3)) {
      $msg .= ' Run `iconv -l` to see all supported charsets.'.PHP_EOL;
    }
    throw new CliError($msg);
  }

  static function checkModule($func, $desc, $module) {
    if (!function_exists($func)) {
      throw new CliError("$desc requires PHP $module module.");
    }
  }

  // This doesn't reset options, keeping values of previous parseArgv() call
  // (if it was done).
  function parseArgv(array $argv) {
    $this->scriptFile = basename(array_shift($argv));

    while (null !== $arg = array_shift($argv)) {
      if ($arg[0] === '-' and $arg !== '-') {
        switch ($arg) {
          case '-h':
            $this->inputPath = null;
            break 2;
          case '-c':
            $this->coveragePath = array_shift($argv);
            break;
          case '-cf':
            $this->fullCoverage = true;
            break;
          case '-m':
            $this->addMeta = true;
            break;
          case '-i':
            $this->dumpStructure = true;
            break;
          case '-im':
            $this->dumpMotions = true;
            break;
          case '-is':
            $this->dumpStatistics = true;
            break;
          case '-w':
            $takeNext = isset($argv[0][0]) && $argv[0][0] !== '-';
            $this->watch = $takeNext ? array_shift($argv) : 0.1;
            break;
          case '-nx':
            $this->skipConverted = true;
            break;
          case '-T':
            $this->selfTest = true;
            break;
          case '-ep':
            $this->ignoreParseError = true;
            break;
          case '-ei':
            $this->ignoreBadInputFiles = true;
            break;
          case '-ew':
            $this->failOnWarning = true;
            break;
          case '-ef':
            $this->fullErrors = true;
            break;
          case '-s':
            $s = array_shift($argv);
            $this->charset = isset(static::$charsets[$lower = strtolower($s)])
              ? static::$charsets[$lower] : $s;
            break;
          case '-ih':
          case '-iH':     // alias
            $this->inputFormat = 'h3';
            break;
          case '-ij':
            $this->inputFormat = 'json';
            break;
          case '-ip':
            $this->inputFormat = 'php';
            break;
          case '-oh':
            $this->outputFormat = 'h3';
            break;
          case '-oH':
            $this->outputFormat = 'h3u';
            break;
          case '-oj':
            $this->outputFormat = 'json';
            break;
          case '-op':
            $this->outputFormat = 'php';
            break;
          case '-of':
            $this->outputSubfolders = false;
            break;
          case '-og':
            $s = array_shift($argv);
            if ($s === '-') {
              $this->outputMapFormat = false;
            } else {
              // PHP 7.4+ warns on wrong input symbols.
              $hex = ltrim($s, '0..9a..fA..F') === '' ? hexdec($s) : null;
              foreach (H3M::$formats as $num => $str) {
                if ($num === $hex or !strcasecmp($str, $s)) {
                  $this->outputMapFormat = $str;
                  break;
                }
              }
              if (!$this->outputMapFormat) {
                throw new CliError("Invalid -og: $s (run -h for known).");
              }
            }
            break;
          case '-pH':
            $this->partialParse = Context::HEADER;
            break;
          case '-ph':
            $this->partialParse = Context::ADDITIONAL;
            break;
          case '-pt':
            $this->partialParse = Context::TILES;
            break;
          case '-pO':
            $this->partialParse = Context::OBJECT_KINDS;
            break;
          case '-po':
            $this->partialParse = Context::OBJECTS;
            break;
          case '-pe':
            $this->partialParse = Context::EVENTS;
            break;
          default:
            throw new CliError("Invalid -option: $arg.");
        }
      } elseif (!isset($this->inputPath)) {
        $this->inputPath = $arg;
        $this->inputFolder = is_dir($this->inputPath);
      } elseif (!isset($this->outputPath)) {
        $this->outputPath = $arg;
      } else {
        throw new CliError("Superfluous argument: $arg.");
      }
    }
  }

  function handleException($e) {
    error_clear_last();   // for handleShutdown()
    $stream = $this->errorStream;

    if ($e instanceof CliError) {
      fprintf($stream, '(!) %s%s', $e->getMessage(), PHP_EOL);
      fprintf($stream, "Run `$this->scriptFile -h` for usage details.%s", PHP_EOL);
      exit(1);
    } else {
      $e and fprintf($stream, '(!) %s%s', $e, PHP_EOL);

      if ($this->lastContext) {
        fwrite($stream, PHP_EOL);

        $this->fullErrors
          ? $this->lastContext->printState(compact('stream'))
          : $this->lastContext->printState([
              'stream' => $stream,
              'outlineLines' => 60,
              'lastMotions' => 5,
              'motionLines' => 2,
            ]);
      }

      exit(2);
    }
  }

  function handleShutdown() {
    // Don't output error info, PHP already did it, just call printState().
    error_get_last() and $this->handleException(null);
  }

  function helpText() {
    $formats = '';
    foreach (H3M::$formats as $int => $name) {
      $formats .= sprintf('%s0x%02X (%s)', $formats ? ', ' : '', $int, $name);
    }

    $charsets = '';
    foreach (static::$charsets as $alias => $charset) {
      $charsets .= sprintf('%s%s=%s', $charsets ? ', ' : '',
                           strtoupper($alias), $charset);
    }

    $version = Context::VERSION;
    $d = DIRECTORY_SEPARATOR;

    return <<<HELP
Usage: $this->scriptFile [-options] directory$d|file.(h3m|json|dat) [output|-]

This script is standalone, independent from other HeroWO.js scripts on purpose.
It's both a library and a console tool (if not include'd and if no "H3M2JSON"
constant is defined). Requires PHP 5.6+, iconv, Zlib and a throw'ing
set_error_handler().

Note: $this->scriptFile does not produce playable maps for HeroWO. Rather, it produces
easy to consume intermediate format. Use h3m2herowo.php to convert HeroWO maps.

Input is either an H3M (compressed or not) or JSON/DAT file or folder thereof
(recursive). DAT holds output of PHP serialize(). Output format _version: V$version.
Supported map formats (-og): $formats.

If input is a file, output must be a file, dash (= stdout), omitted
(= input.xxx) or a folder (output{$d}input.xxx).

If input is a folder, output must be a folder or omitted (= input). Input folder
structure (subfolders) is preserved unless -of (map file names must be unique).

Options:
  -h              show this help text
  -s CHARSET      iconv charset for H3M strings (defaults to cp1250 - English);
                  aliases: $charsets
  -ep             use partially parsed data in case of H3M read errors (ignore);
                  still fail if file doesn't look like H3M (unless have -ei)
  -ei             ignore bad files in input folder (process as many as possible)
  -ew             treat warnings as errors (halt)
  -ef             full errors: output context for all warnings and full dump in
                  case of error; consider stderr redirection and more memory:
                  php -d memory_limit=1G 2>err.txt $this->scriptFile ...
  -ih -ij -ip     process input: H3M (compressed or not), JSON, PHP .dat
                  (defaults to .ext of first input file)
  -oh -oj -op     ...produce output (defaults to .ext of output file; if
                  unavailable then to -oh or -oj depending on input)
  -oH             ...produce uncompressed H3M (not loadable by HoMM 3)
  -nx             folder input: do not process already converted files
  -of             folder input: flat output, no subfolders
  -og GAME        override output map format version (hex or string); H3M input
                  to H3M output: '-' to recompile rather than just un/compress
  -m              add "_meta" fields to produced JSON (-oj)
  -i              dump structure of all successfully processed maps
  -im             dump binary stream motions (only for -ih)
  -is             dump combined map statistics (not implemented yet)
  -pH -ph -pt     partial H3M parse: basic header, +all header, +tiles
  -pO -po -pe     ...+object kinds on map, +objects, +timed events
  -w [MS]         file input only: reprocess on change, checked every MS (0.1)
  -T              test mode: convert output back into input format and compare
  -c OUT.HTML     generate script coverage; needs XDebug
  -cf             cover all functions, even not related to parsing

Examples:
  [-ih] -oH Maps$d       - decompress (_inflate()) all H3M files in Maps$d
  [-oj] Map.h3m         - convert the H3M to Map.json
  -oH Map.json          - compile the JSON to uncompressed H3M
  -oh -og roe Map.h3m   - downgrade the H3M's version to RoE; "roe" same as "0E"

This implementation is based on "h3m-The-Corpus.txt" which explains the complete
format based on multiple sources. Keep this script and that file in sync when
making changes to one of them.

This script is licensed under public domain/Unlicense.

HELP;
  }

  function mayTakeOver(array $argv) {
    // This constant (declared in the root NS) allows including this script as a
    // library from inside php -a.
    if (count(get_included_files()) < 2 and !defined('H3M2JSON')) {
      static::takeOver($argv);
    }
  }

  // Will terminate the script.
  function takeOver(array $argv) {
    set_error_handler(function ($severity, $msg, $file, $line) {
      throw new \ErrorException($msg, 0, $severity, $file, $line);
    }, -1);

    set_exception_handler([$this, 'handleException']);
    register_shutdown_function([$this, 'handleShutdown']);
    $this->parseArgv($argv);
    exit($this->run());
  }

  function run() {
    if (!file_exists($this->inputPath ?? '')) {
      fwrite($this->outputStream, $this->helpText());
      return 1;
    }

    // Only needed when -i/-o is H/h but checking it globally for simplicity.
    static::checkCharset($this->charset);

    static $suffixes = ['g' => 2 ** 30, 'm' => 2 ** 20, 'k' => 2 ** 10];
    $mem = ini_get('memory_limit');
    $ref = &$suffixes[strtolower(substr($mem, -1))];
    $ref and $mem = ((int) $mem) * $ref;
    if ($mem < static::$memory_limit * 2 ** 20) {
      fprintf($this->errorStream, '(*) may run out of memory (%d MiB); consider -dmemory_limit=%sM%s',
        $mem / 2 ** 20, static::$memory_limit, PHP_EOL);
    }

    if ($this->coveragePath) {
      $this->checkModule('xdebug_info', 'Coverage output (-c)', 'XDebug');
      xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE |
                                 XDEBUG_CC_BRANCH_CHECK);
      // This script is self-sufficient and doesn't include any other scripts so
      // filtering is unnecessary. However, if user employs other files then
      // this sould be called prior to including this file, as per
      // https://xdebug.org/docs/code_coverage
      //xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, [__FILE__]);
    }

    $code = $this->process();

    if ($this->coveragePath) {
      $this->writeCoverage($this->coveragePath,
        xdebug_get_code_coverage(),
        array_flip($this->fullCoverage ? [] : static::$coverageIgnore));

      xdebug_stop_code_coverage();
    }

    return $code;
  }

  protected function process() {
    // It will functionally work anyway but this makes console output pretty.
    $inputPath = rtrim($this->inputPath, '\\/');
    $outputPath = isset($this->outputPath) ? rtrim($this->outputPath, '\\/') : null;
    $total = $failed = $skipped = 0;
    $processed = $stats = [];

    if ($this->inputFolder) {
      if ($outputPath === '-') {
        throw new CliError('Output cannot be stdout if input is a folder.');
      }
      if ($this->watch) {
        throw new CliError('Watch mode (-w) is unsupported if input is a folder.');
      }

      $walk = function ($subdir)
          use (&$walk, $inputPath, $outputPath,
               &$total, &$failed, &$skipped,
               &$processed, &$stats) {
        foreach (scandir("$inputPath/$subdir") as $file) {
          $full = $inputPath.DIRECTORY_SEPARATOR.$subdir.$file;
          $format = static::extensionToFormat(strrchr($file, '.'));
          if (is_dir($full)) {
            if ($file !== '.' and $file !== '..') {
              $walk($subdir.$file.DIRECTORY_SEPARATOR);
            }
          } elseif ($format) {
            if (!isset($this->inputFormat)) {
              $this->inputFormat = $format;
            }
            if ($this->inputFormat === $format) {
              $total++;
              // Preserve folder structure in output when recursively walking
              // input, if -of is not given.
              $path = isset($outputPath) ? $outputPath : $inputPath;
              $this->outputSubfolders and $path .= DIRECTORY_SEPARATOR.$subdir;
              file_exists($path) or mkdir($path, 0777, true);
              try {
                $info = $this->processFile($full, $path, true);
                if ($info) {
                  // An H3M object with all the metadata consumes a lot of
                  // memory (up to 100 MiB per large map). Pull info we need for
                  // statistics and explicitly free it (unset($info)) before the
                  // next iteration.
                  isset($info['h3m']) and $processed[] = $info['h3m']->format;
                  $this->dumpStatistics and $this->collectStatistics($info['h3m'], $stats);
                  unset($info);
                } else {
                  $skipped++;
                }
                continue;
              } catch (\Throwable $e) {
              } catch (\Exception $e) {}
              $failed++;
              if ($this->ignoreBadInputFiles) {
                fprintf($this->errorStream, "(!) Input file ignored due to %s: %s%s",
                  get_class($e), trim($e->getMessage()), PHP_EOL);
              } else {
                throw $e;
              }
            }
          }
        }
      };

      $time = microtime(true);
      $walk('');
      $time = microtime(true) - $time;

      printf('Finished in %ss; peak mem %dM; processed %d file%s (%d successfully, %d not), %d skipped',
        number_format($time), memory_get_peak_usage(true) / 2 ** 20,
        $total -= $skipped, $total === 1 ? '' : 's',
        count($processed), $failed, $skipped);

      if ($processed) {
        $formats = [];
        foreach ($processed as $format) {
          $format = sprintf(is_int($format) ? '0x02X' : '%s', $format);
          $ref = &$formats[$format];
          $ref[0] = "$format ".(count($ref ?: ['']));
          $ref[] = null;
        }
        ksort($formats);
        fwrite($this->outputStream, ': '.join(', ', array_column($formats, 0)));
      }

      fwrite($this->outputStream, PHP_EOL);
      $stats and $this->printStatistics($stats);
      return !$failed && $processed ? 0 : 3;
    } else {
      if ($this->skipConverted) {
        throw new CliError('Skip converted (-nx) is unsupported if input is a file.');
      }

      isset($outputPath) or $outputPath = dirname($inputPath);

      if (is_dir($outputPath) and $outputPath !== '-') {
        $outputPath .= DIRECTORY_SEPARATOR;
      }

      $prev = null;

      do {
        try {
          $cur = [filesize($inputPath), filemtime($inputPath)];
        } catch (\ErrorException $e) {
          // run() has checked whether $inputFile actually existed or not.
          // If it has disappeared, stop for a second and recheck again, once.
          // Especially with a low -w interval, we might get in between a
          // program overwriting our file (removing it and creating).
          if (isset($cur)) {
            $cur = null;
            sleep(1);
            continue;
          } else {
            fprintf($this->errorStream, "Input file deleted, stop -w'atching%s", PHP_EOL);
            break;
          }
        }

        if ($prev !== $cur) {
          $prev = $cur;
          $time = microtime(true);

          extract($this->processFile($inputPath, $outputPath, is_dir($outputPath)));
          //fwrite($this->errorStream, (memory_get_peak_usage(true) / 2 ** 20).PHP_EOL);

          if (isset($dataStream)) {   // output is stdout
            rewind($dataStream);
            fpassthru($dataStream);
            fclose($dataStream);
          }

          if ($this->dumpStatistics and !$stats) {  // first iteration only
            $this->collectStatistics($h3m, $stats);
            $this->printStatistics($stats);
          }

          if ($this->watch) {
            $time = microtime(true) - $time;
            fprintf($infoStream, '--- (+%ds) waiting ---%s', $time, PHP_EOL);
          }
        }

        usleep($this->watch * 1000000);
        clearstatcache(true, realpath($inputPath));
      } while ($this->watch);

      return 0;
    }
  }

  // $inputPath may be a resource if -T is on.
  protected function processFile($inputPath, $outputPath, $autoOutputPath) {
    $infoStream = $this->inputFolder ? $this->outputStream : $this->errorStream;
    $selfTesting = is_resource($inputPath);
    fprintf($infoStream, '%s ', $selfTesting ? 'self-test' : $inputPath);
    $inputFormat = $this->inputFormat;
    $outputFormat = $this->outputFormat;

    if (!$inputFormat) {
      $inputFormat = static::extensionToFormat(strrchr($inputPath, '.'));
    }

    if (!$inputFormat) {
      throw new CliError('Cannot auto-detect input format. Give one of the -i... options.');
    }

    if (!$outputFormat) {
      if (!$autoOutputPath) {
        $outputFormat = static::extensionToFormat(strrchr($outputPath, '.'));
      }

      $outputFormat or $outputFormat = $inputFormat === 'h3' ? 'json' : 'h3';
    }

    if ($autoOutputPath) {
      $outputPath .= static::basename($inputPath).'.'.static::$formatToExtension[$outputFormat];
    }

    fprintf($infoStream, '-> %s ', $outputPath);

    if ($outputPath !== '-' and
        $this->checkSkipConverted($outputPath, $inputPath)) {
      fprintf($infoStream, '(-nx)%s', PHP_EOL);
      return;
    }

    $dumpInfo = function (Structure $h3m, Context $cx = null) use ($outputPath) {
      $stream = $outputPath === '-' ? $this->errorStream : $this->outputStream;
      if ($ds = $this->dumpStructure) {
        fwrite($stream, $h3m->dump());
      }
      if ($dm = isset($cx) && $this->dumpMotions) {
        $ds and fwrite($stream, PHP_EOL);
        $cx->stream->printState(compact('stream'));
        fwrite($stream, PHP_EOL);
      }
      if (($ds or $dm) and $this->inputFolder) {
        fwrite($stream, PHP_EOL);
      }
    };

    // window of 31 produces output compatible with gzopen()/gzencode() and
    // HoMM 3. Note: 30 won't work because some maps are compressed with maximum
    // window size (15) and 30 sets cap to a smaller size leading to Zlib error.
    //
    // https://bugs.php.net/bug.php?id=68556
    $zlib = ['window' => 31];

    $f = $selfTesting ? $inputPath : fopen($inputPath, 'rb');
    try {
      if ($inputFormat === 'h3') {
        $inputFormat = isCompressed($f) ? 'h3' : 'h3u';
        rewind($f);
      }

      fprintf($infoStream, '(%s -> %s)%s', static::$formatToTitle[$inputFormat],
        static::$formatToTitle[$outputFormat], PHP_EOL);

      switch ($inputFormat) {
        case 'h3':
          $this->checkModule('gzopen', 'Processing compressed H3M', 'Zlib');
          $filter = stream_filter_append($f, 'zlib.inflate', STREAM_FILTER_READ, $zlib);
        case 'h3u':
          // Quick de/compression.
          $recompress = (in_array($outputFormat, ['h3', 'h3u']) and
            $this->outputMapFormat === null);
          if ($recompress) {
            // Load the entire file in case the input file is also the output.
            $recompress = stream_get_contents($f);
            $h3m = null;
          } else {
            $cx = $this->createContext('parsing H3M', function ($cx) use ($f, &$filter) {
              $cx->readUncompressedH3M(new PhpStream($f, isset($filter)));
            });
            $h3m = $cx->h3m;
          }
          break;

        case 'json':
          $h3m = json_decode(stream_get_contents($f));
          if (!is_object($h3m)) {
            throw new CliError('Malformed JSON.');
          } elseif (empty($h3m->_version) or $h3m->_version !== Context::VERSION) {
            throw new CliError('Unsupported JSON _version.');
          }
          // We need to preserve H3M PHP type info, not use stdClass everywhere.
          throw new CliError('Converting from JSON (-ij) is not implemented yet.'); // XXX+I
          break;

        case 'php':
          if (version_compare(PHP_VERSION, '7.0.0') >= 0) {
            $classes = array_filter(get_declared_classes(), function ($c) {
              return $c instanceof Structure;
            });
            $h3m = unserialize(stream_get_contents($f), $classes);
          } else {
            $h3m = unserialize(stream_get_contents($f));
          }
          break;
      }

      if ($h3m) {
        if (preg_match_all('/^.|[A-Z]/', $h3m->sizeText, $match)) {
          $sizeText = strtoupper(join($match[0]));
          $sizeText === 'EL' and $sizeText = 'XL';
        } else {
          $sizeText = 'non-std';
        }

        fprintf($infoStream, '  [%s] %dx%dx%d (%s) %s P%d/T%d +%s -%s O%d E%d "%s" "%s"%s',
          $h3m->format, $h3m->size, $h3m->size, $h3m->twoLevels + 1, $sizeText,
          $h3m->difficulty,
          count($h3m->players ?: []),
          count(array_unique(array_column($h3m->players ?: [], 'team'))),
          !$h3m->victoryCondition ? 'N' :
            preg_replace('/^.+VictoryCondition|[^A-Z]/', '', get_class($h3m->victoryCondition)),
          !$h3m->lossCondition ? 'N' :
            preg_replace('/^.+LossCondition|[^A-Z]/', '', get_class($h3m->lossCondition)),
          count($h3m->objects ?: []),
          count($h3m->events ?: []),
          $h3m->name,
          strlen($h3m->description ?? '') > 18
            ? substr($h3m->description, 0, 15).'...' : $h3m->description,
          PHP_EOL);

        $dumpInfo($h3m, isset($cx) ? $cx : null);

        if ($this->outputMapFormat) {
          $h3m->format = $this->outputMapFormat;
        }
      }

      $selfTesting ? isset($filter) and stream_filter_remove($filter) : fclose($f);
      $f = fopen($outputPath === '-' ? 'php://temp' : $outputPath, 'w+b');
      Structure::$includeMeta = $this->addMeta;

      switch ($outputFormat) {
        case 'h3':
          static::checkModule('gzopen', 'Processing compressed H3M', 'Zlib');
          $filter = stream_filter_append($f, 'zlib.deflate', STREAM_FILTER_WRITE, $zlib);
        case 'h3u':
          if (isset($recompress) and $recompress !== false) {
            fwrite($f, $recompress);
          } else {
            $cx = $this->createContext('building H3M', function ($cx)
                use ($f, $outputFormat, $h3m) {
              $cx->h3m = $h3m;
              $cx->writeUncompressedH3M(new PhpStream($f, $outputFormat === 'h3'));
            });
            $outputFormat === 'h3' and stream_filter_remove($filter);
            $dumpInfo($h3m, $cx);
          }
          break;

        case 'json':
          fwrite($f, json_encode($h3m, JSON_FLAGS));
          break;

        case 'php':
          fwrite($f, serialize($h3m));
          break;
      }

      if ($this->selfTest and !$selfTesting) {
        $this->inputFormat = $outputFormat;
        $this->outputFormat = $inputFormat;
        try {
          rewind($f);
          $rebuilt = $this->processFile($f, '-', false)['dataStream'];
        } finally {
          $this->inputFormat = $inputFormat;
          $this->outputFormat = $outputFormat;
        }
        try {
          // stream_get_contents($f, null, 0) doesn't work - apparently $length
          // (in case of null) is determined before seeking to 0.
          rewind($f);
          rewind($rebuilt);
          if (stream_get_contents($f) !== stream_get_contents($rebuilt)) {
            // This doesn't necessary indicate a problem - in fact, it usually
            // doesn't because the way we serialize .h3m differs from SoD's map
            // editor - we don't include first two dummy object attributes, our
            // values for fields of non-playable Player-s differ, etc.
            //
            // -T is still useful to see if the compiled data doesn't cause
            // exceptions when parsed. Data comparison is best carried by
            // converting the original and compiled H3Ms to JSON, removing
            // "kind" lines from both and diff'ing them.
            throw new \LogicException('Self-test (-T) has produced data different from the input.');
          }
        } finally {
          fclose($rebuilt);
        }
      }
    } catch (\Throwable $e) {
      $closeAndThrow = $e;
    } catch (\Exception $e) {
      $closeAndThrow = $e;
    }

    if (isset($closeAndThrow)) {
      try {
        // Not closing the stream upon exception during createContext() to
        // enable printState() in handleException() called by handleShutdown().
        if (!$selfTesting and !$this->lastContext) {
          fclose($f);
        }
      } catch (\Throwable $e) {
      } catch (\Exception $e) {
        // Silence.
      }

      throw $closeAndThrow;
    }

    // $dataStream is opened in r/w mode and positioned after the data.
    $dataStream = null;
    $outputPath === '-' ? $dataStream = $f : fclose($f);
    // h3m is empty if inputFormat is a h3/h3u and outputFormat is a h3/h3u.
    return compact('inputPath', 'outputPath', 'inputFormat', 'outputFormat',
                   'h3m', 'infoStream', 'dataStream');
  }

  protected function checkSkipConverted($outputPath, $inputPath) {
    return $this->skipConverted and is_file($outputPath);
  }

  protected function createContext($action, callable $func) {
    Structure::$metaEpoch++;
    $this->lastContext = $cx = new Context;
    $cx->charset = $this->charset;
    $cx->partial = $this->partialParse;
    $cx->warner = function ($msg) use ($cx) {
      if ($this->failOnWarning) {
        throw new \Exception("Warning treated as error (-ew): $msg");
      } else {
        fprintf($this->errorStream, "(*) %s%s", $msg, PHP_EOL);
        if ($this->fullErrors) {
          $cx->printState(['stream' => $this->errorStream]);
          fwrite($this->errorStream, PHP_EOL);
        }
      }
    };
    try {
      $func($cx);
      $e = null;
    } catch (\Throwable $e) {
    } catch (\Exception $e) {}
    if ($e) {
      if ($this->ignoreParseError and !$cx->isUnknown()) {
        fprintf($this->errorStream, "(!) Halted %s due to %s: %s%s",
          $action, get_class($e), trim($e->getMessage()), PHP_EOL);
        if ($this->fullErrors) {
          $cx->printState(['stream' => $this->errorStream]);
          fwrite($this->errorStream, PHP_EOL);
        }
      } else {
        throw $e;
      }
    }
    $this->lastContext = null;
    return $cx;
  }

  // XXX=I
  protected function collectStatistics(H3M $h3m, array &$stats) {
  }

  // XXX=I
  protected function printStatistics(array &$stats) {
  }

  protected function writeCoverage($file, array $coverage, array $ignore = []) {
    //$lines = file(__FILE__, FILE_IGNORE_NEW_LINES);

    preg_match('/\\n(.+)\\n/u', highlight_file(__FILE__, true), $match);
    $parts = preg_split('~(<span[^>]*>|</span>|<br />)~u', $match[1], -1,
                        PREG_SPLIT_DELIM_CAPTURE);
    $parts[] = '<br />';
    $lines = $spans = [];
    $buf = '';

    foreach ($parts as $i => $part) {
      if ($i % 2) {
        if ($part[1] === 'b') {
          $lines[] = $buf;
          $buf = join($spans);
          continue;
        } else {
          $part[1] === 's' ? $spans[] = $part : array_pop($spans);
        }
      }
      $buf .= $part;
    }

    foreach ($lines as &$ref) {
      $ref = ['code' => $ref, 'ignored' => 0, 'branches' => [], 'out' => []];
    }

    $or = function (&$ref, $v) {
      $ref |= $v;
    };

    foreach ($coverage[__FILE__]['functions'] as $func => $cov) {
      $ignored = array_key_exists($func, $ignore);

      foreach ($cov['branches'] as $op => $branch) {
        foreach (range($branch['line_start'], $branch['line_end']) as $line) {
          $ref = &$lines[$line - 1];
          $ref['ignored'] |= $ignored;

          if (!$ignored) {
            $ref['branches'][$func][] = $op;
            $or($ref['out'][-1], $branch['hit']);

            array_map(function ($op, $hit) use (&$ref, $or) {
              $or($ref['out'][$op], $hit);
            }, $branch['out'], $branch['out_hit']);
          }
        }
      }
    }

    $html = [];

    foreach ($lines as $i => $line) {
      ksort($line['out']);
      $hit = count(array_filter($line['out']));

      $html[] = sprintf('<tr id="L%d" class="%s %s" title="%s">',
        $i + 1,
        $line['branches'] ? 'cov' : ($line['ignored'] ? 'ig' : 'no'),
        $line['branches']
          ? $hit === count($line['out']) ? 'covy' : ($hit ? 'covp' : 'covn')
          : '',
        $line['branches']
          ? sprintf('Code ran in %d of %d branches. Part of %s().',
              $hit, count($line['out']),
              join('(), ', array_unique(array_keys($line['branches']))))
          : ($line['ignored'] ? 'Part of ignore list (see -cf).' : '')
      );

      $html[] = sprintf('<th class="ln">%d</th>', $i + 1);

      $html[] = sprintf('<td class="outs">%s</td>',
        join(array_map(function ($hit) {
          return sprintf('<%s>•</%s>', $hit ? 'b' : 'u', $hit ? 'b' : 'u');
        }, $line['out'])));

      $html[] = sprintf('<td><code>%s</code></td>',
        //htmlspecialchars($line['code'], ENT_NOQUOTES, 'utf-8'));
        str_replace('&nbsp;', ' ', $line['code']));

      $html[] = "</tr>\n";
    }

    $html = join($html);
    $html = <<<HTML
<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <style>
      code { white-space: pre-wrap; }
      .ig { background: #f1f1f1; }
      .covn { background: #fdd; }
      .covp { background: #fff1f1; }
      .covy { background: #f1fff1; }
      b { color: green; }
      u { color: red; }
    </style>
  </head>
  <body>
    <table>
$html    </table>
  </body>
</html>
HTML;

    file_put_contents($file, $html);
  }
}

isset($argv) and (new CLI)->mayTakeOver($argv);
