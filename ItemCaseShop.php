<?php

/**
 * @name ItemCaseShop
 * @main ItemCaseShop\Loader
 * @author bl_3an_dev
 * @version 1.0.1v
 * @api 3.0.0
 */


/** - GITHUB: https://github.com/bl-3an-dev/ItemCaseShop 
 *  - LICENSE : https://github.com/bl-3an-dev/ItemCaseShop/blob/main/LICENSE
 *  - 1.0.0v | 첫 릴리즈
 *  - 1.0.1v | is_numeric 관련 추가
 *  - 구동하는데 앞서 EconomyAPI 플러그인이 필요합니다.
 */


namespace ItemCaseShop;

use pocketmine\block\Block;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;

use pocketmine\entity\Entity;

use pocketmine\item\Item;

use pocketmine\event\block\BlockBreakEvent;

use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerInteractEvent;

use pocketmine\math\Vector3;

use pocketmine\network\mcpe\protocol\AddItemActorPacket;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;

use pocketmine\utils\Config;

use pocketmine\Player;

use onebone\economyapi\EconomyAPI;

class Loader extends \pocketmine\plugin\PluginBase{

    /** @var array */
    public $add, $del, $eid, $touch;

    /** @var Config | array */
    public $data, $db;

    public $prefix = '§d+§f';

    public function onEnable(): void {

        if (!is_dir($this->getDataFolder()))
            @mkdir($this->getDataFolder());
        
        $this->data = new Config($this->getDataFolder() . 'shop.json', Config::JSON);
        $this->db = $this->data->getAll();

        $this->getServer()->getCommandMap()->register('MC', new ShopCommand($this));
        $this->getServer()->getCommandMap()->register('BC', new BuyCommand($this));
        $this->getServer()->getCommandMap()->register('SC', new SellCommand($this));

        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);

    }

    public function onDisable(): void {

        $this->data->setAll($this->db);
        $this->data->save();

    }

    public function koreanWonFormat($money): string { // from solo5star , EconomyAPI
        
        $str = '';
        
        $elements = [];
        
        if ($money >= 1000000000000){
            
            $elements[] = floor($money / 1000000000000) . '조';
            
            $money %= 1000000000000;
            
        }
        
        if ($money >= 100000000){
            
            $elements[] = floor($money / 100000000) . '억';
            
            $money %= 100000000;
            
        }
        
        if ($money >= 10000){
            
            $elements[] = floor($money / 10000) . '만';
            
            $money %= 10000;
            
        }
        
        if (count($elements) == 0 || $money > 0){
            
            $elements[] = $money;
            
        }
        
        return implode(' ', $elements) . '원';
        
    }

    public function addCase($item, $pos): void {

        $pk = new AddItemActorPacket();

        $pk->entityRuntimeId = $this->eid[$pos[0] . ':' . $pos[1] . ':' . $pos[2]] = Entity::$entityCount++;

        $pk->item = $item->setCount(1);

        $pk->position = new Vector3($pos[0] + 0.5, $pos[1] + 0.25, $pos[2] + 0.5);

        $pk->motion = new Vector3(0, 0, 0);

        $pk->metadata = [
            Entity::DATA_FLAGS => [Entity::DATA_TYPE_LONG, 1 << Entity::DATA_FLAG_IMMOBILE],
            Entity::DATA_SCALE => [Entity::DATA_TYPE_FLOAT, 0.01],
            Entity::DATA_ALWAYS_SHOW_NAMETAG => [Entity::DATA_TYPE_BYTE, 1]
        ];

        foreach($this->getServer()->getOnlinePlayers() as $players){

            $players->sendDataPacket($pk);

        }

        $this->addTag($item, $pos);

    }

    public function addTag($item, $pos): void {

        $pk = new SetActorDataPacket();

        $pk->entityRuntimeId = $this->eid[$pos[0] . ':' . $pos[1] . ':' . $pos[2]];

        $pk->metadata = [Entity::DATA_NAMETAG => [Entity::DATA_NAMETAG, $item->getName()]];

        foreach($this->getServer()->getOnlinePlayers() as $players){

            $players->sendDataPacket($pk);

        }

    }

    public function delCase($eid): void {

        $pk = new RemoveActorPacket();

        $pk->entityUniqueId = $eid;

        foreach($this->getServer()->getOnlinePlayers() as $players){

            $players->sendDataPacket($pk);

        }

    }

    public function spawnCase(): void {

        if (!isset($this->db['shop']))
            return;

        foreach($this->db['shop'] as $datas => $key){

            $pos = explode(':', $datas);

            $item = Item::jsonDeserialize([
                'id' => $key['id'],
                'damage' => $key['dmg'],
                'count' => 1,
                'nbt' => base64_decode($key['nbt'], true)
            ]);

            $this->addCase($item, $pos);

        }

    }

}

class ShopCommand extends Command{

    /** @var Loader */
    public $owner;

    public function __construct(Loader $owner){

        $this->owner = $owner;

        parent::__construct('shop', 'ShopCommand', '/shop');

    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {

        if (!($sender instanceof Player) or !$sender->isOp()){

            $sender->sendMessage($this->owner->prefix . ' 해당 명령어를 실행 할 수 없습니다');

            return true;

        }

        if (!isset($args[0])){

            $sender->sendMessage($this->owner->prefix . ' /shop [add|a] [구매가] [판매가] : 상점 아이템을 추가합니다');
            $sender->sendMessage($this->owner->prefix . ' /shop [del|d] : 상점 아이템을 삭제합니다');

            return true;

        }

        if ($args[0] === 'add' or $args[0] === 'a'){

            if (count($args) < 3 or !is_numeric($args[1]) or !is_numeric($args[2])){

                $sender->sendMessage($this->owner->prefix . ' /shop [add|a] [구매가] [판매가] : 상점 아이템을 추가합니다');

                return true;

            }

            if ($sender->getInventory()->getItemInHand()->getId() === Item::AIR){

                $sender->sendMessage('failed');
    
                return true;
    
            }

            $sender->sendMessage($this->owner->prefix . ' 상점 아이템을 추가하려면 들고 있는 아이템을 유리에 터치하세요');

            $this->owner->add[$sender->getName()] = [];
            $this->owner->add[$sender->getName()]['buy'] = $args[1];
            $this->owner->add[$sender->getName()]['sell'] = $args[2];

            return true;

        }

        if ($args[0] === 'del' or $args[0] === 'd'){

            $sender->sendMessage($this->owner->prefix . ' 상점 아이템을 삭제하려면 아이템을 제거할 유리를 터치하세요');
            
            $this->owner->del[$sender->getName()] = [];

            return true;
        }

        return true;
    }

}

class BuyCommand extends Command{

    /** @var Loader */
    public $owner;

    public function __construct(Loader $owner){

        $this->owner = $owner;

        parent::__construct('구매', 'BuyCommand', '/구매');

    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {

        if (!isset($args[0]) or !is_numeric($args[0])){

            $sender->sendMessage($this->owner->prefix . ' /구매 (갯수) | 선택한 상점 아이템을 갯수만큼 구매합니다');

            return true;

        }

        if (!isset($this->owner->touch[$sender->getName()])){

            $sender->sendMessage($this->owner->prefix . ' 상점에서 아이템을 선택해주세요');

            return true;

        }

        $price = $this->owner->db['shop'][$this->owner->touch[$sender->getName()]]['buy'];

        if (EconomyAPI::getInstance()->myMoney($sender) < $price * $args[0]){

            $sender->sendMessage($this->owner->prefix . ' 아이템을 구매할 돈이 부족합니다');

            return true;
        }

        $item = Item::jsonDeserialize([
            'id' => $this->owner->db['shop'][$this->owner->touch[$sender->getName()]]['id'],
            'damage' => $this->owner->db['shop'][$this->owner->touch[$sender->getName()]]['dmg'],
            'count' => (int) $args[0],
            'nbt' => base64_decode($this->owner->db['shop'][$this->owner->touch[$sender->getName()]]['nbt'], true)
        ]);

        $before = $this->owner->koreanWonFormat(EconomyAPI::getInstance()->myMoney($sender));

        EconomyAPI::getInstance()->reduceMoney($sender, $price * (int) $args[0]);

        $after = $this->owner->koreanWonFormat(EconomyAPI::getInstance()->myMoney($sender));

        $sender->sendMessage($this->owner->prefix . ' 성공적으로 아이템 구매가 완료되었습니다');
        $sender->sendMessage($this->owner->prefix . ' 변경: ' . $before . ' -> ' . $after);

        $sender->getInventory()->addItem($item);

        unset($this->owner->touch[$sender->getName()]);

        return true;

    }

}

class SellCommand extends Command{

    /** @var Loader */
    public $owner;

    public function __construct(Loader $owner){

        $this->owner = $owner;

        parent::__construct('판매', 'SellCommand', '/판매');

    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {

        if (!isset($args[0]) or !is_numeric($args[0])){

            $sender->sendMessage($this->owner->prefix . ' /판매 (갯수) | 선택한 상점 아이템을 갯수만큼 판매합니다');

            return true;

        }

        if (!isset($this->owner->touch[$sender->getName()])){

            $sender->sendMessage($this->owner->prefix . ' 상점에서 아이템을 선택해주세요');

            return true;

        }

        $item = Item::jsonDeserialize([
            'id' => $this->owner->db['shop'][$this->owner->touch[$sender->getName()]]['id'],
            'damage' => $this->owner->db['shop'][$this->owner->touch[$sender->getName()]]['dmg'],
            'count' => (int) $args[0],
            'nbt' => base64_decode($this->owner->db['shop'][$this->owner->touch[$sender->getName()]]['nbt'], true)
        ]);

        if (!$sender->getInventory()->contains($item)){

            $sender->sendMessage($this->owner->prefix . ' 판매할 아이템이 부족합니다');

            return true;

        }

        $price = $this->owner->db['shop'][$this->owner->touch[$sender->getName()]]['sell'];

        $before = $this->owner->koreanWonFormat(EconomyAPI::getInstance()->myMoney($sender));

        EconomyAPI::getInstance()->addMoney($sender, $price * (int) $args[0]);

        $after = $this->owner->koreanWonFormat(EconomyAPI::getInstance()->myMoney($sender));

        $sender->sendMessage($this->owner->prefix . ' 성공적으로 아이템 판매가 완료되었습니다');
        $sender->sendMessage($this->owner->prefix . ' 변경: ' . $before . ' -> ' . $after);

        $sender->getInventory()->removeItem($item);

        unset($this->owner->touch[$sender->getName()]);

        return true;

    }

}


class EventListener implements \pocketmine\event\Listener{

    /** @var Loader */
    public $owner;

    public function __construct(Loader $owner){

        $this->owner = $owner;

    }

    public function onJoin(PlayerJoinEvent $event){

        $player = $event->getPlayer();

        $this->owner->spawnCase();

    }

    public function onTouch(PlayerInteractEvent $event){

        $player = $event->getPlayer();
        $item = $event->getItem();
        $block = $event->getBlock();

        if ($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){

            if ($block->getId() === Block::GLASS){

                if (isset($this->owner->add[$player->getName()])){

                    $player->sendMessage($this->owner->prefix . ' 성공적으로 상점 아이템을 추가했습니다');

                    $this->owner->addCase($item, [$block->x, $block->y, $block->z]);

                    $this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z] = [];
                    $this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['id'] = $item->getId();
                    $this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['dmg'] = $item->getDamage();
                    $this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['nbt'] = base64_encode($item->getCompoundTag());
                    $this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['buy'] = $this->owner->add[$player->getName()]['buy'];
                    $this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['sell'] = $this->owner->add[$player->getName()]['sell'];

                    unset($this->owner->add[$player->getName()]);

                }

                if (isset($this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]) and isset($this->owner->del[$player->getName()])){

                    $player->sendMessage($this->owner->prefix . ' 성공적으로 상점 아이템을 삭제했습니다');

                    $this->owner->delCase($this->owner->eid[$block->x . ':' . $block->y . ':' . $block->z]);
                    unset($this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]);
                    unset($this->owner->eid[$block->x . ':' . $block->y . ':' . $block->z]);
                    unset($this->owner->del[$player->getName()]);

                }
                
                if (isset($this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z])){

                    $item = Item::jsonDeserialize([
                        'id' => $this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['id'],
                        'damage' => $this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['dmg'],
                        'count' => 1,
                        'nbt' => base64_decode($this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['nbt'], true)
                    ]);
                    
                    $player->sendMessage('- - - - - - - - - -');
                    $player->sendMessage($this->owner->prefix . ' 아이템 이름: ' . $item->getName());
                    $player->sendMessage($this->owner->prefix . ' 구매가: §a' . $this->owner->koreanWonFormat($this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['buy']));
                    $player->sendMessage($this->owner->prefix . ' 판매가: §a' . $this->owner->koreanWonFormat($this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['sell']));
                    $player->sendMessage($this->owner->prefix . ' /구매 (갯수) or /판매 (갯수)');
                    $player->sendMessage('- - - - - - - - - -');

                    $player->sendPopUp('§f구매가: §a' . $this->owner->koreanWonFormat($this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['buy']) . "\n" . '§f판매가: §a' . $this->owner->koreanWonFormat($this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z]['sell']));

                    $this->owner->touch[$player->getName()] = $block->x . ':' . $block->y . ':' . $block->z;

                }

            }
            
        }

    }

    public function onBreak(BlockBreakEvent $event){

        $player = $event->getPlayer();
        $block = $event->getBlock();
        
        if (isset($this->owner->db['shop'][$block->x . ':' . $block->y . ':' . $block->z])){

            $event->setCancelled();

        }

    }

}