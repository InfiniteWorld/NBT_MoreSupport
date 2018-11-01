<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\nbt;

use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\NamedTag;
use pocketmine\nbt\tag\StringTag;
#ifndef COMPILE
use pocketmine\utils\Binary;
#endif

#include <rules/NBT.h>

/**
 * Base Named Binary Tag encoder/decoder
 */
abstract class NBTStream{

	public $buffer = "";
	public $offset = 0;

	public function get($len) : string{
		if($len < 0){
			$this->offset = strlen($this->buffer) - 1;
			return "";
		}elseif($len === true){
			return substr($this->buffer, $this->offset);
		}

		return $len === 1 ? $this->buffer{$this->offset++} : substr($this->buffer, ($this->offset += $len) - $len, $len);
	}

	public function put(string $v) : void{
		$this->buffer .= $v;
	}

	public function feof() : bool{
		return !isset($this->buffer{$this->offset});
	}

	/**
	 * Decodes NBT from the given binary string and returns it.
	 *
	 * @param string $buffer
	 *
	 * @return CompoundTag
	 */
	public function read(string $buffer) : CompoundTag{
		$this->offset = 0;
		$this->buffer = $buffer;
		$data = $this->readTag();
		$this->buffer = "";

		if(!($data instanceof CompoundTag)){
			throw new \InvalidArgumentException("Expected TAG_Compound at the start of buffer");
		}

		return $data;
	}

	/**
	 * Decodes a list of TAG_Compound into objects and returns them.
	 *
	 * TODO: This is only necessary because we don't have a streams API worth mentioning. Get rid of this in the future.
	 *
	 * @param string $buffer
	 *
	 * @return CompoundTag[]
	 *
	 * @throws \InvalidArgumentException
	 */
	public function readMultiple(string $buffer) : array{
		$this->offset = 0;
		$this->buffer = $buffer;

		$retval = [];

		while(!$this->feof()){
			$next = $this->readTag();
			if(!($next instanceof CompoundTag)){
				throw new \InvalidArgumentException("Expected only TAG_Compound in multiple NBT buffer");
			}
			$retval[] = $next;
		}

		$this->buffer = "";

		return $retval;
	}

	/**
	 * Decodes NBT from the given compressed binary string and returns it. Anything decodable by zlib_decode() can be
	 * processed.
	 *
	 * TODO: This is only necessary because we don't have a streams API worth mentioning. Get rid of this in the future.
	 *
	 * @param string $buffer
	 *
	 * @return CompoundTag
	 */
	public function readCompressed(string $buffer) : CompoundTag{
		return $this->read(zlib_decode($buffer));
	}

	/**
	 * @param CompoundTag $data
	 *
	 * @return string
	 */
	public function write(CompoundTag $data) : string{
		$this->offset = 0;
		$this->buffer = "";

		$this->writeTag($data);
		return $this->buffer;
	}

	/**
	 * @param CompoundTag[] $data
	 *
	 * @return string
	 */
	public function writeMultiple(array $data) : string{
		$this->offset = 0;
		$this->buffer = "";

		foreach($data as $tag){
			$this->writeTag($tag);
		}
		return $this->buffer;
	}

	/**
	 * @param CompoundTag $data
	 * @param int         $compression
	 * @param int         $level
	 *
	 * @return bool|string
	 */
	public function writeCompressed(CompoundTag $data, int $compression = ZLIB_ENCODING_GZIP, int $level = 7){
		return zlib_encode($this->write($data), $compression, $level);
	}

	public function readTag() : ?NamedTag{
		if($this->feof()){
			return null;
		}

		$tagType = $this->getByte();
		if($tagType === NBT::TAG_End){
			return null;
		}

		$tag = NBT::createTag($tagType);
		$tag->setName($this->getString());
		$tag->read($this);

		return $tag;
	}

	public function writeTag(NamedTag $tag) : void{
		$this->putByte($tag->getType());
		$this->putString($tag->getName());
		$tag->write($this);
	}

	public function writeEnd() : void{
		$this->putByte(NBT::TAG_End);
	}

	public function getByte() : int{
		return Binary::readByte($this->get(1));
	}

	public function getSignedByte() : int{
		return Binary::readSignedByte($this->get(1));
	}

	public function putByte(int $v) : void{
		$this->buffer .= Binary::writeByte($v);
	}

	abstract public function getShort() : int;

	abstract public function getSignedShort() : int;

	abstract public function putShort(int $v) : void;


	abstract public function getInt() : int;

	abstract public function putInt(int $v) : void;

	abstract public function getLong();

	abstract public function putLong($v) : void;


	abstract public function getFloat() : float;

	abstract public function putFloat(float $v) : void;


	abstract public function getDouble() : float;

	abstract public function putDouble(float $v) : void;

	public function getString() : string{
		return $this->get($this->getShort());
	}

	/**
	 * @param string $v
	 * @throws \InvalidArgumentException if the string is too long
	 */
	public function putString(string $v) : void{
		$len = strlen($v);
		if($len > 32767){
			throw new \InvalidArgumentException("NBT strings cannot be longer than 32767 bytes, got $len bytes");
		}
		$this->putShort($len);
		$this->put($v);
	}

	/**
	 * @return int[]
	 */
	abstract public function getIntArray() : array;

	/**
	 * @param int[] $array
	 */
	abstract public function putIntArray(array $array) : void;


	/**
	 * @param CompoundTag $data
	 *
	 * @return array
	 */
	public static function toArray(CompoundTag $data) : array{
		$array = [];
		self::tagToArray($array, $data);
		return $array;
	}

	private static function tagToArray(array &$data, NamedTag $tag) : void{
		/** @var CompoundTag[]|ListTag[]|IntArrayTag[] $tag */
		foreach($tag as $key => $value){
			if($value instanceof CompoundTag or $value instanceof ListTag or $value instanceof IntArrayTag){
				$data[$key] = [];
				self::tagToArray($data[$key], $value);
			}else{
				$data[$key] = $value->getValue();
			}
		}
	}

	public static function fromArrayGuesser(string $key, $value) : ?NamedTag{
		if(is_int($value)){
			return new IntTag($key, $value);
		}elseif(is_float($value)){
			return new FloatTag($key, $value);
		}elseif(is_string($value)){
			return new StringTag($key, $value);
		}elseif(is_bool($value)){
			return new ByteTag($key, $value ? 1 : 0);
		}

		return null;
	}

	private static function tagFromArray(NamedTag $tag, array $data, callable $guesser) : void{
		foreach($data as $key => $value){
			if(is_array($value)){
				$isNumeric = true;
				$isIntArray = true;
				foreach($value as $k => $v){
					if(!is_numeric($k)){
						$isNumeric = false;
						break;
					}elseif(!is_int($v)){
						$isIntArray = false;
					}
				}
				$tag{$key} = $isNumeric ? ($isIntArray ? new IntArrayTag($key, []) : new ListTag($key, [])) : new CompoundTag($key, []);
				self::tagFromArray($tag->{$key}, $value, $guesser);
			}else{
				$v = call_user_func($guesser, $key, $value);
				if($v instanceof NamedTag){
					$tag{$key} = $v;
				}
			}
		}
	}

	/**
	 * @param array         $data
	 * @param callable|null $guesser
	 *
	 * @return CompoundTag
	 */
	public static function fromArray(array $data, callable $guesser = null) : CompoundTag{
		$tag = new CompoundTag("", []);
		self::tagFromArray($tag, $data, $guesser ?? [self::class, "fromArrayGuesser"]);
		return $tag;
	}
}
