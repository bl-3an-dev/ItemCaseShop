<?php

/**
 * @name ItemCaseShop
 * @main ItemCaseShop\Loader
 * @author bl_3an_dev
 * @version 1.0.6v
 * @api 3.0.0
 */


/** - GITHUB: https://github.com/bl-3an-dev/ItemCaseShop 
 *  - LICENSE : https://github.com/bl-3an-dev/ItemCaseShop/blob/main/LICENSE
 *  - 1.0.0v | 첫 릴리즈
 *  - 1.0.1v | is_numeric 관련 추가
 *  - 1.0.2v | 중복 추가 방지 및 상점 작업 개선
 *  - 1.0.3v | 월드 구분, (구매,판매) 불가 추가 및 개선
 *  - 1.0.4v | 판매전체 추가 및 개선
 *  - 1.0.45v | 개선
 *  - 1.0.5v | 색유리 지원 및 아이템 스폰 버그 개선
 *  - 1.0.6v | 판매불가 판매 버그 해결, 판매된 아이템 표시
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

    /** @var Config */
    public $shop_data;

    /** @var array */
    public $shop_db;

    public $prefix = '§d+§f';

    public function onEnable(): void {

        if (!is_dir($this->getDataFolder()))
            @mkdir($this->getDataFolder());
        
        $this->shop_data = new Config($this->getDataFolder() . 'shop.json', Config::JSON);
        $this->shop_db = $this->shop_data->getAll();

        $this->getServer()->getCommandMap()->register('MC', new ShopCommand($this));
        $this->getServer()->getCommandMap()->register('BC', new BuyCommand($this));
        $this->getServer()->getCommandMap()->register('SC', new SellCommand($this));
        $this->getServer()->getCommandMap()->register('SAC', new SellAllCommand($this));

        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);

    }

    public function onDisable(): void {

        $this->shop_data->setAll($this->shop_db);
        $this->shop_data->save();

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

    public function addCase($item, $pos, $player): void {

        $pk = new AddItemActorPacket();

        if (!isset($this->eid[$pos[0] . ':' . $pos[1] . ':' . $pos[2] . ':' . $pos[3]])){

            $this->eid[$pos[0] . ':' . $pos[1] . ':' . $pos[2] . ':' . $pos[3]] = Entity::$entityCount++;

        }

        $pk->entityRuntimeId = $this->eid[$pos[0] . ':' . $pos[1] . ':' . $pos[2] . ':' . $pos[3]];

        $pk->item = $item->setCount(1);

        $pk->position = new Vector3($pos[0] + 0.5, $pos[1] + 0.25, $pos[2] + 0.5);

        $pk->motion = new Vector3(0, 0, 0);

        $pk->metadata = [
            Entity::DATA_FLAGS => [Entity::DATA_TYPE_LONG, 1 << Entity::DATA_FLAG_IMMOBILE],
            Entity::DATA_SCALE => [Entity::DATA_TYPE_FLOAT, 0.01],
            Entity::DATA_ALWAYS_SHOW_NAMETAG => [Entity::DATA_TYPE_BYTE, 1]
        ];

        foreach($player->getLevel()->getPlayers() as $players){

            $players->sendDataPacket($pk);

        }

        $this->addTag($item, $pos, $player);

    }

    public function addTag($item, $pos, $player): void {

        $pk = new SetActorDataPacket();

        $pk->entityRuntimeId = $this->eid[$pos[0] . ':' . $pos[1] . ':' . $pos[2] . ':' . $pos[3]];

        $pk->metadata = [Entity::DATA_NAMETAG => [Entity::DATA_NAMETAG, $item->getName()]];

        foreach($player->getLevel()->getPlayers() as $players){

            $players->sendDataPacket($pk);

        }

    }

    public function delCase($eid, $player): void {

        $pk = new RemoveActorPacket();

        $pk->entityUniqueId = $eid;

        foreach($player->getLevel()->getPlayers() as $players){

            $players->sendDataPacket($pk);

        }

    }

    public function spawnCase($player): void {

        if (!isset($this->shop_db['shop']))
            return;

        foreach($this->shop_db['shop'] as $datas => $key){

            $pos = explode(':', $datas);

            $item = Item::jsonDeserialize([
                'id' => $key['id'],
                'damage' => $key['dmg'],
                'nbt' => base64_decode($key['nbt'], true)
            ]);

            $this->addCase($item, $pos, $player);

        }

    }

    public function getItemPrice($item): array {

        $item_price = [];

        if (!isset($this->shop_db['shop']))
            return $item_price;

        foreach($this->shop_db['shop'] as $datas => $key){

            $items = Item::jsonDeserialize([
                'id' => $key['id'],
                'damage' => $key['dmg'],
                'count' => $item->getCount(),
                'nbt' => base64_decode($key['nbt'], true)
            ]);

            if ($item == $items){

                $item_price['buy'] = $key['buy'];
                $item_price['sell'] = $key['sell'];

                break;

            }

        }

        return $item_price;

    }

    public function setItemPrice($item, $buy_price, $sell_price): void {

        if (!isset($this->shop_db['shop']))
            return;

        foreach($this->shop_db['shop'] as $datas => $key){

            $items = Item::jsonDeserialize([
                'id' => $key['id'],
                'damage' => $key['dmg'],
                'count' => $item->getCount(),
                'nbt' => base64_decode($key['nbt'], true)
            ]);

            if ($item == $items){

                $this->shop_db['shop'][$datas]['buy'] = $buy_price;
                $this->shop_db['shop'][$datas]['sell'] = $sell_price;

            }

        }

    }

}

class ShopCommand extends Command{

    /** @var Loader */
    private $owner;

    public function __construct(Loader $owner){

        $this->owner = $owner;

        parent::__construct('shop', 'ShopCommand', '/shop');

    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {

        if (!($sender instanceof Player) || !$sender->isOp()){

            $sender->sendMessage($this->owner->prefix . ' 해당 명령어를 실행 할 수 없습니다');

            return true;

        }

        if (!isset($args[0])){

            $sender->sendMessage($this->owner->prefix . ' /shop [add|a] [구매가] [판매가] : 상점 아이템을 추가합니다');
            $sender->sendMessage($this->owner->prefix . ' /shop [del|d] : 상점 아이템을 삭제합니다');

            return true;

        }

        if ($args[0] === 'add' || $args[0] === 'a'){

            if (count($args) < 3 || !is_numeric($args[1]) || !is_numeric($args[2])){

                $sender->sendMessage($this->owner->prefix . ' /shop [add|a] [구매가] [판매가] : 상점 아이템을 추가합니다');

                return true;

            }

            if (isset($this->owner->add[$sender->getName()])){

                $sender->sendMessage($this->owner->prefix . ' 이미 상점 아이템 추가 모드가 켜져있습니다');

                return true;

            }

            $item = $sender->getInventory()->getItemInHand();

            if ($item->getId() === Item::AIR){

                $sender->sendMessage($this->owner->prefix . ' 상점 아이템을 추가할 아이템을 들어주세요');
    
                return true;
    
            }

            $sender->sendMessage($this->owner->prefix . ' 상점 아이템을 추가하려면 들고 있는 아이템을 유리에 터치하세요');
            $sender->sendMessage($this->owner->prefix . ' 상점 아이템 추가 모드가 켜졌습니다');

            $this->owner->add[$sender->getName()] = [];
            $this->owner->add[$sender->getName()]['buy'] = $args[1];
            $this->owner->add[$sender->getName()]['sell'] = $args[2];

            return true;

        }

        if ($args[0] === 'del' || $args[0] === 'd'){

            $sender->sendMessage($this->owner->prefix . ' 상점 아이템을 삭제하려면 아이템을 제거할 유리를 터치하세요');
            
            $this->owner->del[$sender->getName()] = [];

            return true;
        }

        return true;
    }

}

class BuyCommand extends Command{

    /** @var Loader */
    private $owner;

    public function __construct(Loader $owner){

        $this->owner = $owner;

        parent::__construct('구매', 'BuyCommand', '/구매');

    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {

        if (!isset($args[0]) || !is_numeric($args[0]) || $args[0] < 1){

            $sender->sendMessage($this->owner->prefix . ' /구매 (갯수) | 선택한 상점 아이템을 갯수만큼 구매합니다');

            return true;

        }

        if (!isset($this->owner->touch[$sender->getName()])){

            $sender->sendMessage($this->owner->prefix . ' 상점에서 아이템을 선택해주세요');

            return true;

        }

        if ($this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['buy'] <= 0){

            $sender->sendMessage($this->owner->prefix . ' 아이템을 구매할 할 수 없습니다');

            return true;

        }

        $buy_price = $this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['buy'];

        if (EconomyAPI::getInstance()->myMoney($sender) < $buy_price * $args[0]){

            $sender->sendMessage($this->owner->prefix . ' 아이템을 구매할 돈이 부족합니다');

            return true;
        }

        $item = Item::jsonDeserialize([
            'id' => $this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['id'],
            'damage' => $this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['dmg'],
            'count' => (int) $args[0],
            'nbt' => base64_decode($this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['nbt'], true)
        ]);

        $before = $this->owner->koreanWonFormat(EconomyAPI::getInstance()->myMoney($sender));

        EconomyAPI::getInstance()->reduceMoney($sender, $buy_price * (int) $args[0]);

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
    private $owner;

    public function __construct(Loader $owner){

        $this->owner = $owner;

        parent::__construct('판매', 'SellCommand', '/판매');

    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {

        if (!isset($args[0]) || !is_numeric($args[0]) || $args[0] < 1){

            $sender->sendMessage($this->owner->prefix . ' /판매 (갯수) | 선택한 상점 아이템을 갯수만큼 판매합니다');

            return true;

        }

        if (!isset($this->owner->touch[$sender->getName()])){

            $sender->sendMessage($this->owner->prefix . ' 상점에서 아이템을 선택해주세요');

            return true;

        }

        if ($this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['sell'] <= 0){

            $sender->sendMessage($this->owner->prefix . ' 아이템을 판매할 할 수 없습니다');

            return true;

        }

        $item = Item::jsonDeserialize([
            'id' => $this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['id'],
            'damage' => $this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['dmg'],
            'count' => (int) $args[0],
            'nbt' => base64_decode($this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['nbt'], true)
        ]);

        if (!$sender->getInventory()->contains($item)){

            $sender->sendMessage($this->owner->prefix . ' 판매할 아이템이 부족합니다');

            return true;

        }

        $sell_price = $this->owner->shop_db['shop'][$this->owner->touch[$sender->getName()]]['sell'];

        $before = $this->owner->koreanWonFormat(EconomyAPI::getInstance()->myMoney($sender));

        EconomyAPI::getInstance()->addMoney($sender, $sell_price * (int) $args[0]);

        $after = $this->owner->koreanWonFormat(EconomyAPI::getInstance()->myMoney($sender));

        $sender->sendMessage($this->owner->prefix . ' 성공적으로 아이템 판매가 완료되었습니다');
        $sender->sendMessage($this->owner->prefix . ' 변경: ' . $before . ' -> ' . $after);

        $sender->getInventory()->removeItem($item);

        unset($this->owner->touch[$sender->getName()]);

        return true;

    }

}

class SellAllCommand extends Command{

    /** @var Loader */
    private $owner;

    public function __construct(Loader $owner){

        $this->owner = $owner;

        parent::__construct('판매전체', 'SellAllCommand', '/판매전체');

    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {

        $this->inventoryIndex = 0;

        $itemList = [];

        $before = $this->owner->koreanWonFormat(EconomyAPI::getInstance()->myMoney($sender));

        while ($this->inventoryIndex < $sender->getInventory()->getSize()){ // from solo5star , SMarket

            $content = $sender->getInventory()->getItem($this->inventoryIndex++);

            if (!$content instanceof Item || $content->getId() === Item::AIR){
                continue;
            }

            $item_price = $this->owner->getItemPrice($content);

            if (empty($item_price)){
                continue;
            }

            if ($item_price['sell'] <= 0){
                continue;
            }

            $itemList[] = $content->getName();

            EconomyAPI::getInstance()->addMoney($sender, $item_price['sell'] * $content->getCount());

            $sender->getInventory()->removeItem($content);
            
        }

        $after = $this->owner->koreanWonFormat(EconomyAPI::getInstance()->myMoney($sender));

        $sender->sendMessage($this->owner->prefix . ' 인벤토리에 있는 모든 아이템을 판매했습니다');
        $sender->sendMessage($this->owner->prefix . ' (' . implode(', ', $itemList) . ')');
        $sender->sendMessage($this->owner->prefix . ' 변경: ' . $before . ' -> ' . $after);

        return true;

    }

}

class EventListener implements \pocketmine\event\Listener{

    /** @var Loader */
    private $owner;

    public function __construct(Loader $owner){

        $this->owner = $owner;

    }

    public function onJoin(PlayerJoinEvent $event){

        $this->owner->spawnCase($event->getPlayer());

    }

    public function onTouch(PlayerInteractEvent $event){

        $player = $event->getPlayer();
        $item = $event->getItem();
        $block = $event->getBlock();

        if ($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){

            if ($block->getId() === Block::GLASS || $block->getId() === Block::STAINED_GLASS){

                if (isset($this->owner->add[$player->getName()])){

                    if (isset($this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()])){

                        $player->sendMessage($this->owner->prefix . ' 이미 상점 아이템이 설정되어있습니다');

                        $event->setCancelled();

                        return true;

                    }

                    if (!empty($this->owner->getItemPrice($item))){

                        $player->sendMessage($this->owner->prefix . ' 해당 아이템의 모든 §a구매가격§f과 §a판매가격§f이 변경되었습니다');
        
                        $this->owner->setItemPrice($item, $this->owner->add[$player->getName()]['buy'], $this->owner->add[$player->getName()]['sell']);
        
                    }

                    $player->sendMessage($this->owner->prefix . ' 성공적으로 상점 아이템을 추가했습니다');
                    $player->sendMessage($this->owner->prefix . ' 상점 아이템 추가 모드가 꺼졌습니다');

                    $this->owner->addCase($item, [$block->x, $block->y, $block->z, $block->getLevel()->getFolderName()], $player);

                    $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()] = [];
                    $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['id'] = $item->getId();
                    $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['dmg'] = $item->getDamage();
                    $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['nbt'] = $item->hasCompoundTag() ? base64_encode($item->getCompoundTag()) : '';
                    $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['buy'] = $this->owner->add[$player->getName()]['buy'];
                    $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['sell'] = $this->owner->add[$player->getName()]['sell'];

                    unset($this->owner->add[$player->getName()]);

                    $event->setCancelled();

                    return true;

                }
                
                if (isset($this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()])){

                    if (isset($this->owner->del[$player->getName()])){

                        $player->sendMessage($this->owner->prefix . ' 성공적으로 상점 아이템을 삭제했습니다');

                        $this->owner->delCase($this->owner->eid[$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()], $player);

                        unset($this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]);
                        unset($this->owner->eid[$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]);
                        unset($this->owner->del[$player->getName()]);

                        $event->setCancelled();

                        return true;

                    }

                    $item = Item::jsonDeserialize([
                        'id' => $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['id'],
                        'damage' => $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['dmg'],
                        'nbt' => base64_decode($this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['nbt'], true)
                    ]);

                    $buy_price = $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['buy'];
                    $sell_price = $this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()]['sell'];
                    
                    $player->sendMessage('- - - - - - - - - -');
                    $player->sendMessage($this->owner->prefix . ' 아이템 이름: ' . $item->getName());
                    $player->sendMessage($this->owner->prefix . ' 구매가: §a' . ($buy_price <= 0 ? '§c구매 불가' : $this->owner->koreanWonFormat($buy_price)));
                    $player->sendMessage($this->owner->prefix . ' 판매가: §a' . ($sell_price <= 0 ? '§c판매 불가' : $this->owner->koreanWonFormat($sell_price)));
                    $player->sendMessage($this->owner->prefix . ' /구매 (갯수) or /판매 (갯수)');
                    $player->sendMessage('- - - - - - - - - -');

                    $player->sendPopUp('§f구매가: §a' . ($buy_price <= 0 ? '§c구매 불가' : $this->owner->koreanWonFormat($buy_price)) . "\n" . '§f판매가: §a' . ($sell_price <= 0 ? '§c판매 불가' : $this->owner->koreanWonFormat($sell_price)));

                    $this->owner->touch[$player->getName()] = $block->x . ':' . $block->y . ':' . $block->z. ':' . $block->getLevel()->getFolderName();

                    $event->setCancelled();

                    return true;

                }

            }
            
        }

    }

    public function onBreak(BlockBreakEvent $event){

        $player = $event->getPlayer();
        $block = $event->getBlock();
        
        if (isset($this->owner->shop_db['shop'][$block->x . ':' . $block->y . ':' . $block->z . ':' . $block->getLevel()->getFolderName()])){

            $event->setCancelled();

        }

    }

}

?>