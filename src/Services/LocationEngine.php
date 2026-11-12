<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

final class LocationEngine
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const OLC_ALPHABET = '23456789CFGHJMPQRVWX';

    private const KENYA_CITY_CENTROIDS = [
        'nairobi'  => [-1.286389, 36.817223],
        'kikuyu'   => [-1.246300, 36.662900],
        'mombasa'  => [-4.043477, 39.668206],
        'kisumu'   => [-0.091702, 34.767956],
        'nakuru'   => [-0.303099, 36.080025],
        'eldoret'  => [0.514277, 35.269779],
        'thika'    => [-1.033260, 37.069330],
        'nyeri'    => [-0.420130, 36.947590],
        'kiambu'   => [-1.171390, 36.835560],
        'machakos' => [-1.517680, 37.263410],
    ];

    /**
     * 2-Level Place Taxonomy (Level 1: Country -> Level 2: City / County / State) (#96, #97, #98).
     * Includes all 47 Kenyan Counties + key towns, East African neighbors, and major Busherian diaspora hubs.
     */
        public const PLACE_HIERARCHY = [
        'Afghanistan' => ['Badakhshān', 'Baghlān', 'Balkh', 'Bādghīs', 'Bāmyān', 'Dāykundī', 'Farāh', 'Fāryāb', 'Ghaznī', 'Ghōr', 'Helmand', 'Herāt', 'Jowzjān', 'Kandahār', 'Khōst', 'Kunaṟ', 'Kunduz', 'Kābul', 'Kāpīsā', 'Laghmān', 'Lōgar', 'Nangarhār', 'Nīmrōz', 'Nūristān', 'Paktiyā', 'Paktīkā', 'Panjshayr', 'Parwān', 'Samangān', 'Sar-e Pul', 'Takhār', 'Uruzgān', 'Wardak', 'Zābul'],
        'Albania' => ['Berat', 'Dibër', 'Durrës', 'Elbasan', 'Fier', 'Gjirokastër', 'Korçë', 'Kukës', 'Lezhë', 'Shkodër', 'Tiranë', 'Vlorë'],
        'Algeria' => ['Adrar', 'Alger', 'Annaba', 'Aïn Defla', 'Aïn Témouchent', 'Batna', 'Biskra', 'Blida', 'Bordj Badji Mokhtar', 'Bordj Bou Arréridj', 'Bouira', 'Boumerdès', 'Béchar', 'Béjaïa', 'Béni Abbès', 'Chlef', 'Constantine', 'Djanet', 'Djelfa', 'El Bayadh', 'El Meghaier', 'El Meniaa', 'El Oued', 'El Tarf', 'Ghardaïa', 'Guelma', 'Illizi', 'In Guezzam', 'In Salah', 'Jijel', 'Khenchela', 'Laghouat', 'M\'sila', 'Mascara', 'Mila', 'Mostaganem', 'Médéa', 'Naama', 'Oran', 'Ouargla', 'Ouled Djellal', 'Oum el Bouaghi', 'Relizane', 'Saïda', 'Sidi Bel Abbès', 'Skikda', 'Souk Ahras', 'Sétif', 'Tamanrasset', 'Tiaret', 'Timimoun', 'Tindouf', 'Tipaza', 'Tissemsilt', 'Tizi Ouzou', 'Tlemcen', 'Touggourt', 'Tébessa'],
        'Andorra' => ['Andorra la Vella', 'Canillo', 'Encamp', 'Escaldes-Engordany', 'La Massana', 'Ordino', 'Sant Julià de Lòria'],
        'Angola' => ['Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 'Cuanza-Norte', 'Cuanza-Sul', 'Cunene', 'Huambo', 'Huíla', 'Luanda', 'Lunda-Norte', 'Lunda-Sul', 'Malange', 'Moxico', 'Namibe', 'Uíge', 'Zaire'],
        'Antigua and Barbuda' => ['Barbuda', 'Redonda', 'Saint George', 'Saint John', 'Saint Mary', 'Saint Paul', 'Saint Peter', 'Saint Philip'],
        'Argentina' => ['Buenos Aires', 'Catamarca', 'Chaco', 'Chubut', 'Ciudad Autónoma de Buenos Aires', 'Corrientes', 'Córdoba', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz', 'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego', 'Tucumán'],
        'Armenia' => ['Aragac̣otn', 'Ararat', 'Armavir', 'Erevan', 'Geġark\'unik\'', 'Kotayk\'', 'Loṙi', 'Syunik\'', 'Tavuš', 'Vayoć Jor', 'Širak'],
        'Australia' => ['Australian Capital Territory', 'New South Wales', 'Northern Territory', 'Queensland', 'South Australia', 'Tasmania', 'Victoria', 'Western Australia'],
        'Austria' => ['Burgenland', 'Kärnten', 'Niederösterreich', 'Oberösterreich', 'Salzburg', 'Steiermark', 'Tirol', 'Vorarlberg', 'Wien'],
        'Azerbaijan' => ['Abşeron', 'Astara', 'Ağcabədi', 'Ağdam', 'Ağdaş', 'Ağstafa', 'Ağsu', 'Bakı', 'Balakən', 'Beyləqan', 'Biləsuvar', 'Bərdə', 'Cəbrayıl', 'Cəlilabad', 'Daşkəsən', 'Füzuli', 'Goranboy', 'Göygöl', 'Göyçay', 'Gədəbəy', 'Gəncə', 'Hacıqabul', 'Kürdəmir', 'Kəlbəcər', 'Laçın', 'Lerik', 'Lənkəran', 'Masallı', 'Mingəçevir', 'Naftalan', 'Naxçıvan', 'Neftçala', 'Oğuz', 'Qax', 'Qazax', 'Qobustan', 'Quba', 'Qubadlı', 'Qusar', 'Qəbələ', 'Saatlı', 'Sabirabad', 'Salyan', 'Samux', 'Siyəzən', 'Sumqayıt', 'Tovuz', 'Tərtər', 'Ucar', 'Xankəndi', 'Xaçmaz', 'Xocalı', 'Xocavənd', 'Xızı', 'Yardımlı', 'Yevlax', 'Zaqatala', 'Zəngilan', 'Zərdab', 'İmişli', 'İsmayıllı', 'Şabran', 'Şamaxı', 'Şirvan', 'Şuşa', 'Şəki', 'Şəmkir'],
        'Bahamas' => ['Acklins', 'Berry Islands', 'Bimini', 'Black Point', 'Cat Island', 'Central Abaco', 'Central Andros', 'Central Eleuthera', 'City of Freeport', 'Crooked Island and Long Cay', 'East Grand Bahama', 'Exuma', 'Grand Cay', 'Harbour Island', 'Hope Town', 'Inagua', 'Long Island', 'Mangrove Cay', 'Mayaguana', 'Moore\'s Island', 'New Providence', 'North Abaco', 'North Andros', 'North Eleuthera', 'Ragged Island', 'Rum Cay', 'San Salvador', 'South Abaco', 'South Andros', 'South Eleuthera', 'Spanish Wells', 'West Grand Bahama'],
        'Bahrain' => ['Al Janūbīyah', 'Al Muḩarraq', 'Al ‘Āşimah', 'Ash Shamālīyah'],
        'Bangladesh' => ['Bagerhat', 'Bandarban', 'Barguna', 'Barishal', 'Bhola', 'Bogura', 'Brahmanbaria', 'Chandpur', 'Chapai Nawabganj', 'Chattogram', 'Chuadanga', 'Cox\'s Bazar', 'Cumilla', 'Dhaka', 'Dinajpur', 'Faridpur', 'Feni', 'Gaibandha', 'Gazipur', 'Gopalganj', 'Habiganj', 'Jamalpur', 'Jashore', 'Jhalakathi', 'Jhenaidah', 'Joypurhat', 'Khagrachhari', 'Khulna', 'Kishoreganj', 'Kurigram', 'Kushtia', 'Lakshmipur', 'Lalmonirhat', 'Madaripur', 'Magura', 'Manikganj', 'Meherpur', 'Moulvibazar', 'Munshiganj', 'Mymensingh', 'Naogaon', 'Narail', 'Narayanganj', 'Narsingdi', 'Natore', 'Netrakona', 'Nilphamari', 'Noakhali', 'Pabna', 'Panchagarh', 'Patuakhali', 'Pirojpur', 'Rajbari', 'Rajshahi', 'Rangamati', 'Rangpur', 'Satkhira', 'Shariatpur', 'Sherpur', 'Sirajganj', 'Sunamganj', 'Sylhet', 'Tangail', 'Thakurgaon'],
        'Barbados' => ['Christ Church', 'Saint Andrew', 'Saint George', 'Saint James', 'Saint John', 'Saint Joseph', 'Saint Lucy', 'Saint Michael', 'Saint Peter', 'Saint Philip', 'Saint Thomas'],
        'Belarus' => ['Bresckaja voblasć', 'Gomel\'skaja oblast\'', 'Gorod Minsk', 'Grodnenskaja oblast\'', 'Mahilioŭskaja voblasć', 'Minskaja oblast\'', 'Viciebskaja voblasć'],
        'Belgium' => ['Antwerpen', 'Brabant wallon', 'Hainaut', 'Limburg', 'Liège', 'Luxembourg', 'Namur', 'Oost-Vlaanderen', 'Vlaams-Brabant', 'West-Vlaanderen'],
        'Belize' => ['Belize', 'Cayo', 'Corozal', 'Orange Walk', 'Stann Creek', 'Toledo'],
        'Benin' => ['Alibori', 'Atacora', 'Atlantique', 'Borgou', 'Collines', 'Couffo', 'Donga', 'Littoral', 'Mono', 'Ouémé', 'Plateau', 'Zou'],
        'Bhutan' => ['Bumthang', 'Chhukha', 'Dagana', 'Gasa', 'Haa', 'Lhuentse', 'Monggar', 'Paro', 'Pema Gatshel', 'Punakha', 'Samdrup Jongkhar', 'Samtse', 'Sarpang', 'Thimphu', 'Trashi Yangtse', 'Trashigang', 'Trongsa', 'Tsirang', 'Wangdue Phodrang', 'Zhemgang'],
        'Bolivia' => ['Chuquisaca', 'Cochabamba', 'El Beni', 'La Paz', 'Oruro', 'Pando', 'Potosí', 'Santa Cruz', 'Tarija'],
        'Bosnia and Herzegovina' => ['Brčko distrikt', 'Federacija Bosne i Hercegovine', 'Republika Srpska'],
        'Botswana' => ['Central', 'Chobe', 'Francistown', 'Gaborone', 'Ghanzi', 'Jwaneng', 'Kgalagadi', 'Kgatleng', 'Kweneng', 'Lobatse', 'North East', 'North West', 'Selibe Phikwe', 'South East', 'Southern', 'Sowa Town'],
        'Brazil' => ['Acre', 'Alagoas', 'Amapá', 'Amazonas', 'Bahia', 'Ceará', 'Distrito Federal', 'Espírito Santo', 'Goiás', 'Maranhão', 'Mato Grosso', 'Mato Grosso do Sul', 'Minas Gerais', 'Paraná', 'Paraíba', 'Pará', 'Pernambuco', 'Piauí', 'Rio Grande do Norte', 'Rio Grande do Sul', 'Rio de Janeiro', 'Rondônia', 'Roraima', 'Santa Catarina', 'Sergipe', 'São Paulo', 'Tocantins'],
        'Brunei' => ['Belait', 'Brunei-Muara', 'Temburong', 'Tutong'],
        'Bulgaria' => ['Blagoevgrad', 'Burgas', 'Dobrich', 'Gabrovo', 'Haskovo', 'Kardzhali', 'Kyustendil', 'Lovech', 'Montana', 'Pazardzhik', 'Pernik', 'Pleven', 'Plovdiv', 'Razgrad', 'Ruse', 'Shumen', 'Silistra', 'Sliven', 'Smolyan', 'Sofia', 'Stara Zagora', 'Targovishte', 'Varna', 'Veliko Tarnovo', 'Vidin', 'Vratsa', 'Yambol'],
        'Burkina Faso' => ['Balé', 'Bam', 'Banwa', 'Bazèga', 'Bougouriba', 'Boulgou', 'Boulkiemdé', 'Comoé', 'Ganzourgou', 'Gnagna', 'Gourma', 'Houet', 'Ioba', 'Kadiogo', 'Komondjari', 'Kompienga', 'Kossi', 'Koulpélogo', 'Kouritenga', 'Kourwéogo', 'Kénédougou', 'Loroum', 'Léraba', 'Mouhoun', 'Nahouri', 'Namentenga', 'Nayala', 'Noumbiel', 'Oubritenga', 'Oudalan', 'Passoré', 'Poni', 'Sanguié', 'Sanmatenga', 'Sissili', 'Soum', 'Sourou', 'Séno', 'Tapoa', 'Tuy', 'Yagha', 'Yatenga', 'Ziro', 'Zondoma', 'Zoundwéogo'],
        'Burundi' => ['Bubanza', 'Bujumbura Mairie', 'Bujumbura Rural', 'Bururi', 'Cankuzo', 'Cibitoke', 'Gitega', 'Karuzi', 'Kayanza', 'Kirundo', 'Makamba', 'Muramvya', 'Muyinga', 'Mwaro', 'Ngozi', 'Rumonge', 'Rutana', 'Ruyigi'],
        'Cabo Verde' => ['Boa Vista', 'Brava', 'Maio', 'Mosteiros', 'Paul', 'Porto Novo', 'Praia', 'Ribeira Brava', 'Ribeira Grande', 'Ribeira Grande de Santiago', 'Sal', 'Santa Catarina', 'Santa Catarina do Fogo', 'Santa Cruz', 'São Domingos', 'São Filipe', 'São Lourenço dos Órgãos', 'São Miguel', 'São Salvador do Mundo', 'São Vicente', 'Tarrafal', 'Tarrafal de São Nicolau'],
        'Cambodia' => ['Baat Dambang', 'Banteay Mean Choăy', 'Kaeb', 'Kampong Chaam', 'Kampong Chhnang', 'Kampong Spueu', 'Kampong Thum', 'Kampot', 'Kandaal', 'Kaoh Kong', 'Kracheh', 'Mondol Kiri', 'Otdar Mean Chey', 'Pailin', 'Phnom Penh', 'Pousaat', 'Preah Sihanouk', 'Preah Vihear', 'Prey Veaeng', 'Rotanak Kiri', 'Siem Reab', 'Stoĕng Trêng', 'Svaay Rieng', 'Taakaev', 'Tbong Khmum'],
        'Cameroon' => ['Adamaoua', 'Centre', 'East', 'Far North', 'Littoral', 'North', 'North-West', 'South', 'South-West', 'West'],
        'Canada' => ['Alberta', 'British Columbia', 'Manitoba', 'New Brunswick', 'Newfoundland and Labrador', 'Northwest Territories', 'Nova Scotia', 'Nunavut', 'Ontario', 'Prince Edward Island', 'Quebec', 'Saskatchewan', 'Yukon'],
        'Central African Republic' => ['Bamingui-Bangoran', 'Bangui', 'Basse-Kotto', 'Gribingui', 'Haut-Mbomou', 'Haute-Kotto', 'Haute-Sangha / Mambéré-Kadéï', 'Kémo-Gribingui', 'Lobaye', 'Mbomou', 'Nana-Mambéré', 'Ombella-Mpoko', 'Ouaka', 'Ouham', 'Ouham-Pendé', 'Sangha', 'Vakaga'],
        'Chad' => ['Bahr el Ghazal', 'Batha', 'Borkou', 'Chari-Baguirmi', 'Ennedi-Est', 'Ennedi-Ouest', 'Guéra', 'Hadjer Lamis', 'Kanem', 'Lac', 'Logone-Occidental', 'Logone-Oriental', 'Mandoul', 'Mayo-Kebbi-Est', 'Mayo-Kebbi-Ouest', 'Moyen-Chari', 'Ouaddaï', 'Salamat', 'Sila', 'Tandjilé', 'Tibesti', 'Ville de Ndjamena', 'Wadi Fira'],
        'Chile' => ['Aisén del General Carlos Ibañez del Campo', 'Antofagasta', 'Arica y Parinacota', 'Atacama', 'Biobío', 'Coquimbo', 'La Araucanía', 'Libertador General Bernardo O\'Higgins', 'Los Lagos', 'Los Ríos', 'Magallanes', 'Maule', 'Región Metropolitana de Santiago', 'Tarapacá', 'Valparaíso', 'Ñuble'],
        'China' => ['Anhui', 'Beijing', 'Chongqing', 'Fujian', 'Gansu', 'Guangdong', 'Guangxi', 'Guizhou', 'Hainan', 'Hebei', 'Heilongjiang', 'Henan', 'Hong Kong SAR', 'Hubei', 'Hunan', 'Inner Mongolia', 'Jiangsu', 'Jiangxi', 'Jilin', 'Liaoning', 'Macao SAR', 'Ningxia', 'Qinghai', 'Shaanxi', 'Shandong', 'Shanghai', 'Shanxi', 'Sichuan', 'Taiwan', 'Tianjin', 'Tibet', 'Xinjiang', 'Yunnan', 'Zhejiang'],
        'Colombia' => ['Amazonas', 'Antioquia', 'Arauca', 'Atlántico', 'Bolívar', 'Boyacá', 'Caldas', 'Caquetá', 'Casanare', 'Cauca', 'Cesar', 'Chocó', 'Cundinamarca', 'Córdoba', 'Distrito Capital de Bogotá', 'Guainía', 'Guaviare', 'Huila', 'La Guajira', 'Magdalena', 'Meta', 'Nariño', 'Norte de Santander', 'Putumayo', 'Quindío', 'Risaralda', 'San Andrés, Providencia y Santa Catalina', 'Santander', 'Sucre', 'Tolima', 'Valle del Cauca', 'Vaupés', 'Vichada'],
        'Comoros' => ['Anjouan', 'Grande Comore', 'Mohéli'],
        'Congo (Brazzaville)' => ['Bouenza', 'Brazzaville', 'Cuvette', 'Cuvette-Ouest', 'Kouilou', 'Likouala', 'Lékoumou', 'Niari', 'Plateaux', 'Pointe-Noire', 'Pool', 'Sangha'],
        'Congo (Democratic Republic / DRC)' => ['Bas-Uélé', 'Haut-Katanga', 'Haut-Lomami', 'Haut-Uélé', 'Ituri', 'Kasaï', 'Kasaï Central', 'Kasaï Oriental', 'Kinshasa', 'Kongo Central', 'Kwango', 'Kwilu', 'Lomami', 'Lualaba', 'Mai-Ndombe', 'Maniema', 'Mongala', 'Nord-Kivu', 'Nord-Ubangi', 'Sankuru', 'Sud-Kivu', 'Sud-Ubangi', 'Tanganyika', 'Tshopo', 'Tshuapa', 'Équateur'],
        'Costa Rica' => ['Alajuela', 'Cartago', 'Guanacaste', 'Heredia', 'Limón', 'Puntarenas', 'San José'],
        'Croatia' => ['Bjelovarsko-bilogorska županija', 'Brodsko-posavska županija', 'Dubrovačko-neretvanska županija', 'Grad Zagreb', 'Istarska županija', 'Karlovačka županija', 'Koprivničko-križevačka županija', 'Krapinsko-zagorska županija', 'Ličko-senjska županija', 'Međimurska županija', 'Osječko-baranjska županija', 'Požeško-slavonska županija', 'Primorsko-goranska županija', 'Sisačko-moslavačka županija', 'Splitsko-dalmatinska županija', 'Varaždinska županija', 'Virovitičko-podravska županija', 'Vukovarsko-srijemska županija', 'Zadarska županija', 'Zagrebačka županija', 'Šibensko-kninska županija'],
        'Cuba' => ['Artemisa', 'Camagüey', 'Ciego de Ávila', 'Cienfuegos', 'Granma', 'Guantánamo', 'Holguín', 'Isla de la Juventud', 'La Habana', 'Las Tunas', 'Matanzas', 'Mayabeque', 'Pinar del Río', 'Sancti Spíritus', 'Santiago de Cuba', 'Villa Clara'],
        'Cyprus' => ['Ammochostos', 'Baf', 'Girne', 'Larnaka', 'Lefkosia', 'Lemesos'],
        'Czechia (Czech Republic)' => ['Jihomoravský kraj', 'Jihočeský kraj', 'Karlovarský kraj', 'Kraj Vysočina', 'Královéhradecký kraj', 'Liberecký kraj', 'Moravskoslezský kraj', 'Olomoucký kraj', 'Pardubický kraj', 'Plzeňský kraj', 'Praha, Hlavní město', 'Středočeský kraj', 'Zlínský kraj', 'Ústecký kraj'],
        'Côte d\'Ivoire' => ['Abidjan', 'Bas-Sassandra', 'Comoé', 'Denguélé', 'Gôh-Djiboua', 'Lacs', 'Lagunes', 'Montagnes', 'Sassandra-Marahoué', 'Savanes', 'Vallée du Bandama', 'Woroba', 'Yamoussoukro', 'Zanzan'],
        'Denmark' => ['Hovedstaden', 'Midtjylland', 'Nordjylland', 'Sjælland', 'Syddanmark'],
        'Djibouti' => ['Ali Sabieh', 'Arta', 'Dikhil', 'Djibouti', 'Obock', 'Tadjourah'],
        'Dominica' => ['Saint Andrew', 'Saint David', 'Saint George', 'Saint John', 'Saint Joseph', 'Saint Luke', 'Saint Mark', 'Saint Patrick', 'Saint Paul', 'Saint Peter'],
        'Dominican Republic' => ['Azua', 'Baoruco', 'Barahona', 'Dajabón', 'Distrito Nacional (Santo Domingo)', 'Duarte', 'El Seibo', 'Elías Piña', 'Espaillat', 'Hato Mayor', 'Hermanas Mirabal', 'Independencia', 'La Altagracia', 'La Romana', 'La Vega', 'María Trinidad Sánchez', 'Monseñor Nouel', 'Monte Cristi', 'Monte Plata', 'Pedernales', 'Peravia', 'Puerto Plata', 'Samaná', 'San Cristóbal', 'San José de Ocoa', 'San Juan', 'San Pedro de Macorís', 'Santiago', 'Santiago Rodríguez', 'Santo Domingo', 'Sánchez Ramírez', 'Valverde'],
        'Ecuador' => ['Azuay', 'Bolívar', 'Carchi', 'Cañar', 'Chimborazo', 'Cotopaxi', 'El Oro', 'Esmeraldas', 'Galápagos', 'Guayas', 'Imbabura', 'Loja', 'Los Ríos', 'Manabí', 'Morona Santiago', 'Napo', 'Orellana', 'Pastaza', 'Pichincha', 'Santa Elena', 'Santo Domingo de los Tsáchilas', 'Sucumbíos', 'Tungurahua', 'Zamora Chinchipe'],
        'Egypt' => ['Alexandria', 'Aswan', 'Asyut', 'Beheira', 'Beni Suef', 'Cairo', 'Dakahlia', 'Damietta', 'Faiyum', 'Gharbia', 'Giza', 'Ismailia', 'Kafr El Sheikh', 'Luxor', 'Matrouh', 'Minya', 'Monufia', 'New Valley', 'North Sinai', 'Port Said', 'Qalyubia', 'Qena', 'Red Sea', 'Sharqia', 'Sohag', 'South Sinai', 'Suez'],
        'El Salvador' => ['Ahuachapán', 'Cabañas', 'Chalatenango', 'Cuscatlán', 'La Libertad', 'La Paz', 'La Unión', 'Morazán', 'San Miguel', 'San Salvador', 'San Vicente', 'Santa Ana', 'Sonsonate', 'Usulután'],
        'Equatorial Guinea' => ['Annobon', 'Bioko Nord', 'Bioko Sud', 'Centro Sud', 'Djibloho', 'Kié-Ntem', 'Littoral', 'Wele-Nzas'],
        'Eritrea' => ['Al Awsaţ', 'Al Janūbī', 'Ansabā', 'Debubawi K’eyyĭḥ Baḥri', 'Gash-Barka', 'Semienawi K’eyyĭḥ Baḥri'],
        'Estonia' => ['Harjumaa', 'Hiiumaa', 'Ida-Virumaa', 'Järvamaa', 'Jõgevamaa', 'Lääne-Virumaa', 'Läänemaa', 'Pärnumaa', 'Põlvamaa', 'Raplamaa', 'Saaremaa', 'Tartumaa', 'Valgamaa', 'Viljandimaa', 'Võrumaa'],
        'Eswatini' => ['Hhohho', 'Lubombo', 'Manzini', 'Shiselweni'],
        'Ethiopia' => ['Addis Ababa', 'Afar', 'Amara', 'Benshangul-Gumaz', 'Dire Dawa', 'Gambela Peoples', 'Harari People', 'Oromia', 'Sidama', 'Somali', 'Southern Nations, Nationalities and Peoples', 'Southwest Ethiopia Peoples', 'Tigrai'],
        'Fiji' => ['Ba', 'Bua', 'Cakaudrove', 'Kadavu', 'Lau', 'Lomaiviti', 'Macuata', 'Nadroga and Navosa', 'Naitasiri', 'Namosi', 'Ra', 'Rewa', 'Serua', 'Tailevu'],
        'Finland' => ['Etelä-Karjala', 'Etelä-Pohjanmaa', 'Etelä-Savo', 'Kainuu', 'Kanta-Häme', 'Keski-Pohjanmaa', 'Keski-Suomi', 'Kymenlaakso', 'Landskapet Åland', 'Lappi', 'Pirkanmaa', 'Pohjanmaa', 'Pohjois-Karjala', 'Pohjois-Pohjanmaa', 'Pohjois-Savo', 'Päijät-Häme', 'Satakunta', 'Uusimaa', 'Varsinais-Suomi'],
        'France' => ['Auvergne-Rhône-Alpes', 'Bourgogne-Franche-Comté', 'Bretagne', 'Centre-Val de Loire', 'Clipperton', 'Corse', 'Grand-Est', 'Guadeloupe', 'Guyane', 'Hauts-de-France', 'La Réunion', 'Martinique', 'Mayotte', 'Normandie', 'Nouvelle-Aquitaine', 'Nouvelle-Calédonie', 'Occitanie', 'Pays-de-la-Loire', 'Polynésie française', 'Provence-Alpes-Côte-d’Azur', 'Saint-Barthélemy', 'Saint-Martin', 'Saint-Pierre-et-Miquelon', 'Terres australes françaises', 'Wallis-et-Futuna', 'Île-de-France'],
        'Gabon' => ['Estuaire', 'Haut-Ogooué', 'Moyen-Ogooué', 'Ngounié', 'Nyanga', 'Ogooué-Ivindo', 'Ogooué-Lolo', 'Ogooué-Maritime', 'Woleu-Ntem'],
        'Gambia' => ['Banjul', 'Central River', 'Lower River', 'North Bank', 'Upper River', 'Western'],
        'Georgia' => ['Abkhazia', 'Ajaria', 'Guria', 'Imereti', 'K\'akheti', 'Kvemo Kartli', 'Mtskheta-Mtianeti', 'Rach\'a-Lechkhumi-Kvemo Svaneti', 'Samegrelo-Zemo Svaneti', 'Samtskhe-Javakheti', 'Shida Kartli', 'Tbilisi'],
        'Germany' => ['Baden-Württemberg', 'Bavaria', 'Berlin', 'Brandenburg', 'Bremen', 'Hamburg', 'Hesse', 'Lower Saxony', 'Mecklenburg-Vorpommern', 'North Rhine-Westphalia', 'Rhineland-Palatinate', 'Saarland', 'Saxony', 'Saxony-Anhalt', 'Schleswig-Holstein', 'Thuringia'],
        'Ghana' => ['Ahafo', 'Ashanti', 'Bono', 'Bono East', 'Central', 'Eastern', 'Greater Accra', 'North East', 'Northern', 'Oti', 'Savannah', 'Upper East', 'Upper West', 'Volta', 'Western', 'Western North'],
        'Greece' => ['Anatolikí Makedonía kai Thráki', 'Attikí', 'Dytikí Elláda', 'Dytikí Makedonía', 'Ionía Nísia', 'Kentrikí Makedonía', 'Kríti', 'Nótio Aigaío', 'Pelopónnisos', 'Stereá Elláda', 'Thessalía', 'Vóreio Aigaío', 'Ágion Óros', 'Ípeiros'],
        'Grenada' => ['Saint Andrew', 'Saint David', 'Saint George', 'Saint John', 'Saint Mark', 'Saint Patrick', 'Southern Grenadine Islands'],
        'Guatemala' => ['Alta Verapaz', 'Baja Verapaz', 'Chimaltenango', 'Chiquimula', 'El Progreso', 'Escuintla', 'Guatemala', 'Huehuetenango', 'Izabal', 'Jalapa', 'Jutiapa', 'Petén', 'Quetzaltenango', 'Quiché', 'Retalhuleu', 'Sacatepéquez', 'San Marcos', 'Santa Rosa', 'Sololá', 'Suchitepéquez', 'Totonicapán', 'Zacapa'],
        'Guinea' => ['Beyla', 'Boffa', 'Boké', 'Coyah', 'Dabola', 'Dalaba', 'Dinguiraye', 'Dubréka', 'Faranah', 'Forécariah', 'Fria', 'Gaoual', 'Guékédou', 'Kankan', 'Kindia', 'Kissidougou', 'Koubia', 'Koundara', 'Kouroussa', 'Kérouané', 'Labé', 'Lola', 'Lélouma', 'Macenta', 'Mali', 'Mamou', 'Mandiana', 'Nzérékoré', 'Pita', 'Siguiri', 'Tougué', 'Télimélé', 'Yomou'],
        'Guinea-Bissau' => ['Bafatá', 'Biombo', 'Bolama / Bijagós', 'Cacheu', 'Gabú', 'Oio', 'Quinara', 'Tombali'],
        'Guyana' => ['Barima-Waini', 'Cuyuni-Mazaruni', 'Demerara-Mahaica', 'East Berbice-Corentyne', 'Essequibo Islands-West Demerara', 'Mahaica-Berbice', 'Pomeroon-Supenaam', 'Potaro-Siparuni', 'Upper Demerara-Berbice', 'Upper Takutu-Upper Essequibo'],
        'Haiti' => ['Artibonite', 'Centre', 'Grande’Anse', 'Nippes', 'Nord', 'Nord-Est', 'Nord-Ouest', 'Ouest', 'Sud', 'Sud-Est'],
        'Honduras' => ['Atlántida', 'Choluteca', 'Colón', 'Comayagua', 'Copán', 'Cortés', 'El Paraíso', 'Francisco Morazán', 'Gracias a Dios', 'Intibucá', 'Islas de la Bahía', 'La Paz', 'Lempira', 'Ocotepeque', 'Olancho', 'Santa Bárbara', 'Valle', 'Yoro'],
        'Hungary' => ['Baranya', 'Borsod-Abaúj-Zemplén', 'Budapest', 'Bács-Kiskun', 'Békés', 'Békéscsaba', 'Csongrád-Csanád', 'Debrecen', 'Dunaújváros', 'Eger', 'Fejér', 'Győr', 'Győr-Moson-Sopron', 'Hajdú-Bihar', 'Heves', 'Hódmezővásárhely', 'Jász-Nagykun-Szolnok', 'Kaposvár', 'Kecskemét', 'Komárom-Esztergom', 'Miskolc', 'Nagykanizsa', 'Nyíregyháza', 'Nógrád', 'Pest', 'Pécs', 'Salgótarján', 'Somogy', 'Sopron', 'Szabolcs-Szatmár-Bereg', 'Szeged', 'Szekszárd', 'Szolnok', 'Szombathely', 'Székesfehérvár', 'Tatabánya', 'Tolna', 'Vas', 'Veszprém', 'Zala', 'Zalaegerszeg', 'Érd'],
        'Iceland' => ['Austurland', 'Höfuðborgarsvæði', 'Norðurland eystra', 'Norðurland vestra', 'Suðurland', 'Suðurnes', 'Vestfirðir', 'Vesturland'],
        'India' => ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh', 'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'],
        'Indonesia' => ['Aceh', 'Bali', 'Banten', 'Bengkulu', 'Gorontalo', 'Jakarta Raya', 'Jambi', 'Jawa Barat', 'Jawa Tengah', 'Jawa Timur', 'Kalimantan Barat', 'Kalimantan Selatan', 'Kalimantan Tengah', 'Kalimantan Timur', 'Kalimantan Utara', 'Kepulauan Bangka Belitung', 'Kepulauan Riau', 'Lampung', 'Maluku', 'Maluku Utara', 'Nusa Tenggara Barat', 'Nusa Tenggara Timur', 'Papua', 'Papua Barat', 'Papua Barat Daya', 'Papua Pengunungan', 'Papua Selatan', 'Papua Tengah', 'Riau', 'Sulawesi Barat', 'Sulawesi Selatan', 'Sulawesi Tengah', 'Sulawesi Tenggara', 'Sulawesi Utara', 'Sumatera Barat', 'Sumatera Selatan', 'Sumatera Utara', 'Yogyakarta'],
        'Iran' => ['Alborz', 'Ardabīl', 'Būshehr', 'Chahār Maḩāl va Bakhtīārī', 'Eşfahān', 'Fārs', 'Golestān', 'Gīlān', 'Hamadān', 'Hormozgān', 'Kermān', 'Kermānshāh', 'Khorāsān-e Jonūbī', 'Khorāsān-e Raẕavī', 'Khorāsān-e Shomālī', 'Khūzestān', 'Kohgīlūyeh va Bowyer Aḩmad', 'Kordestān', 'Lorestān', 'Markazī', 'Māzandarān', 'Qazvīn', 'Qom', 'Semnān', 'Sīstān va Balūchestān', 'Tehrān', 'Yazd', 'Zanjān', 'Āz̄ārbāyjān-e Ghārbī', 'Āz̄ārbāyjān-e Shārqī', 'Īlām'],
        'Iraq' => ['Al Anbār', 'Al Başrah', 'Al Muthanná', 'Al Qādisīyah', 'An Najaf', 'Baghdād', 'Bābil', 'Dhī Qār', 'Diyālá', 'Herêm-î Kurdistan', 'Karbalā’', 'Kirkūk', 'Maysān', 'Nīnawá', 'Wāsiţ', 'Şalāḩ ad Dīn'],
        'Ireland' => ['Carlow', 'Cavan', 'Clare', 'Cork', 'Donegal', 'Dublin', 'Galway', 'Kerry', 'Kildare', 'Kilkenny', 'Laois', 'Leitrim', 'Limerick', 'Longford', 'Louth', 'Mayo', 'Meath', 'Monaghan', 'Offaly', 'Roscommon', 'Sligo', 'Tipperary', 'Waterford', 'Westmeath', 'Wexford', 'Wicklow'],
        'Israel' => ['Al Awsaţ', 'Al Janūbī', 'Al Quds', 'Ash Shamālī', 'H̱efa', 'Tall Abīb'],
        'Italy' => ['Agrigento', 'Alessandria', 'Ancona', 'Arezzo', 'Ascoli Piceno', 'Asti', 'Avellino', 'Bari', 'Barletta-Andria-Trani', 'Belluno', 'Benevento', 'Bergamo', 'Biella', 'Bologna', 'Bolzano', 'Brescia', 'Brindisi', 'Cagliari', 'Caltanissetta', 'Campobasso', 'Caserta', 'Catania', 'Catanzaro', 'Chieti', 'Como', 'Cosenza', 'Cremona', 'Crotone', 'Cuneo', 'Enna', 'Fermo', 'Ferrara', 'Firenze', 'Foggia', 'Forlì-Cesena', 'Frosinone', 'Genova', 'Gorizia', 'Grosseto', 'Imperia', 'Isernia', 'L\'Aquila', 'La Spezia', 'Latina', 'Lecce', 'Lecco', 'Livorno', 'Lodi', 'Lucca', 'Macerata', 'Mantova', 'Massa-Carrara', 'Matera', 'Messina', 'Milano', 'Modena', 'Monza e Brianza', 'Napoli', 'Novara', 'Nuoro', 'Oristano', 'Padova', 'Palermo', 'Parma', 'Pavia', 'Perugia', 'Pesaro e Urbino', 'Pescara', 'Piacenza', 'Pisa', 'Pistoia', 'Pordenone', 'Potenza', 'Prato', 'Ragusa', 'Ravenna', 'Reggio Calabria', 'Reggio Emilia', 'Rieti', 'Rimini', 'Roma', 'Rovigo', 'Salerno', 'Sassari', 'Savona', 'Siena', 'Siracusa', 'Sondrio', 'Sud Sardegna', 'Taranto', 'Teramo', 'Terni', 'Torino', 'Trapani', 'Trento', 'Treviso', 'Trieste', 'Udine', 'Varese', 'Venezia', 'Verbano-Cusio-Ossola', 'Vercelli', 'Verona', 'Vibo Valentia', 'Vicenza', 'Viterbo'],
        'Jamaica' => ['Clarendon', 'Hanover', 'Kingston', 'Manchester', 'Portland', 'Saint Andrew', 'Saint Ann', 'Saint Catherine', 'Saint Elizabeth', 'Saint James', 'Saint Mary', 'Saint Thomas', 'Trelawny', 'Westmoreland'],
        'Japan' => ['Aichi', 'Akita', 'Aomori', 'Chiba', 'Ehime', 'Fukui', 'Fukuoka', 'Fukushima', 'Gifu', 'Gunma', 'Hiroshima', 'Hokkaido', 'Hyogo', 'Ibaraki', 'Ishikawa', 'Iwate', 'Kagawa', 'Kagoshima', 'Kanagawa', 'Kochi', 'Kumamoto', 'Kyoto', 'Mie', 'Miyagi', 'Miyazaki', 'Nagano', 'Nagasaki', 'Nara', 'Niigata', 'Oita', 'Okayama', 'Okinawa', 'Osaka', 'Saga', 'Saitama', 'Shiga', 'Shimane', 'Shizuoka', 'Tochigi', 'Tokushima', 'Tokyo', 'Tottori', 'Toyama', 'Wakayama', 'Yamagata', 'Yamaguchi', 'Yamanashi'],
        'Jordan' => ['Al Balqā’', 'Al Karak', 'Al Mafraq', 'Al ‘Aqabah', 'Al ‘A̅şimah', 'Az Zarqā’', 'Aţ Ţafīlah', 'Irbid', 'Jarash', 'Ma‘ān', 'Mādabā', '‘Ajlūn'],
        'Kazakhstan' => ['Abajskaja oblast’', 'Akmolinskaja oblast\'', 'Aktjubinskaja oblast\'', 'Almatinskaja oblast\'', 'Almaty', 'Astana', 'Atyrauskaja oblast\'', 'Batys Qazaqstan oblysy', 'Karagandinskaja oblast\'', 'Kostanajskaja oblast\'', 'Kyzylordinskaja oblast\'', 'Mangghystaū oblysy', 'Pavlodar oblysy', 'Severo-Kazahstanskaja oblast\'', 'Shyghys Qazaqstan oblysy', 'Shymkent', 'Turkestankaya oblast\'', 'Ulytauskaja oblast’', 'Zhambyl oblysy', 'Zhetisū oblysy'],
        'Kenya' => ['Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo-Marakwet', 'Embu', 'Garissa', 'Homa Bay', 'Isiolo', 'Kajiado', 'Kakamega', 'Kericho', 'Kiambu', 'Kilifi', 'Kirinyaga', 'Kisii', 'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu', 'Machakos', 'Makueni', 'Mandera', 'Marsabit', 'Meru', 'Migori', 'Mombasa', 'Murang\'a', 'Nairobi', 'Nakuru', 'Nandi', 'Narok', 'Nyamira', 'Nyandarua', 'Nyeri', 'Samburu', 'Siaya', 'Taita-Taveta', 'Tana River', 'Tharaka-Nithi', 'Trans Nzoia', 'Turkana', 'Uasin Gishu', 'Vihiga', 'Wajir', 'West Pokot'],
        'Kiribati' => ['Gilbert Islands', 'Line Islands', 'Phoenix Islands'],
        'Korea (North)' => ['Chagang-do', 'Hamgyǒng-bukto', 'Hamgyǒng-namdo', 'Hwanghae-bukto', 'Hwanghae-namdo', 'Kaeseong', 'Kangweonto', 'Nampho', 'P\'yǒngan-bukto', 'P\'yǒngan-namdo', 'P\'yǒngyang', 'Raseon', 'Ryanggang-do'],
        'Korea (South)' => ['Busan-gwangyeoksi', 'Chungcheongbuk-do', 'Chungcheongnam-do', 'Daegu-gwangyeoksi', 'Daejeon-gwangyeoksi', 'Gangwon-teukbyeoljachido', 'Gwangju-gwangyeoksi', 'Gyeonggi-do', 'Gyeongsangbuk-do', 'Gyeongsangnam-do', 'Incheon-gwangyeoksi', 'Jeju-teukbyeoljachido', 'Jeollabuk-do', 'Jeollanam-do', 'Sejong', 'Seoul-teukbyeolsi', 'Ulsan-gwangyeoksi'],
        'Kosovo' => ['Ferizaj', 'Gjakova', 'Gjilan', 'Mitrovica', 'Peja', 'Pristina', 'Prizren'],
        'Kuwait' => ['Al Aḩmadī', 'Al Farwānīyah', 'Al Jahrā’', 'Al ‘Āşimah', 'Mubārak al Kabīr', 'Ḩawallī'],
        'Kyrgyzstan' => ['Batken', 'Bishkek Shaary', 'Chuyskaya oblast\'', 'Dzhalal-Abadskaya oblast\'', 'Gorod Osh', 'Issyk-Kul\'skaja oblast\'', 'Naryn', 'Osh', 'Talas'],
        'Laos' => ['Attapu', 'Bokèo', 'Bolikhamxai', 'Champasak', 'Houaphan', 'Khammouan', 'Louang Namtha', 'Louangphabang', 'Oudômxai', 'Phôngsali', 'Salavan', 'Savannakhét', 'Viangchan', 'Xaignabouli', 'Xaisômboun', 'Xiangkhouang', 'Xékong'],
        'Latvia' => ['Aizkraukles novads', 'Alūksnes novads', 'Augšdaugavas novads', 'Balvu novads', 'Bauskas novads', 'Cēsu novads', 'Daugavpils', 'Dienvidkurzemes Novads', 'Dobeles novads', 'Gulbenes novads', 'Jelgava', 'Jelgavas novads', 'Jēkabpils novads', 'Jūrmala', 'Krāslavas novads', 'Kuldīgas novads', 'Liepāja', 'Limbažu novads', 'Ludzas novads', 'Līvānu novads', 'Madonas novads', 'Mārupes novads', 'Ogres novads', 'Olaines novads', 'Preiļu novads', 'Ropažu novads', 'Rēzekne', 'Rēzeknes novads', 'Rīga', 'Salaspils novads', 'Saldus novads', 'Saulkrastu novads', 'Siguldas novads', 'Smiltenes novads', 'Talsu novads', 'Tukuma novads', 'Valkas novads', 'Valmieras Novads', 'Varakļānu novads', 'Ventspils', 'Ventspils novads', 'Ādažu novads', 'Ķekavas novads'],
        'Lebanon' => ['Aakkâr', 'Al Biqā‘', 'Al Janūb', 'An Nabaţīyah', 'Ash Shimāl', 'Baalbek-Hermel', 'Bayrūt', 'Jabal Lubnān'],
        'Lesotho' => ['Berea', 'Botha-Bothe', 'Leribe', 'Mafeteng', 'Maseru', 'Mohale\'s Hoek', 'Mokhotlong', 'Qacha\'s Nek', 'Quthing', 'Thaba-Tseka'],
        'Liberia' => ['Bomi', 'Bong', 'Gbarpolu', 'Grand Bassa', 'Grand Cape Mount', 'Grand Gedeh', 'Grand Kru', 'Lofa', 'Margibi', 'Maryland', 'Montserrado', 'Nimba', 'River Cess', 'River Gee', 'Sinoe'],
        'Libya' => ['Al Buţnān', 'Al Jabal al Akhḑar', 'Al Jabal al Gharbī', 'Al Jafārah', 'Al Jufrah', 'Al Kufrah', 'Al Marj', 'Al Marqab', 'Al Wāḩāt', 'An Nuqāţ al Khams', 'Az Zāwiyah', 'Banghāzī', 'Darnah', 'Ghāt', 'Mişrātah', 'Murzuq', 'Nālūt', 'Sabhā', 'Surt', 'Wādī al Ḩayāt', 'Wādī ash Shāţi’', 'Ţarābulus'],
        'Liechtenstein' => ['Balzers', 'Eschen', 'Gamprin', 'Mauren', 'Planken', 'Ruggell', 'Schaan', 'Schellenberg', 'Triesen', 'Triesenberg', 'Vaduz'],
        'Lithuania' => ['Alytaus apskritis', 'Kauno apskritis', 'Klaipėdos apskritis', 'Marijampolės apskritis', 'Panevėžio apskritis', 'Tauragės apskritis', 'Telšių apskritis', 'Utenos apskritis', 'Vilniaus apskritis', 'Šiaulių apskritis'],
        'Luxembourg' => ['Capellen', 'Clervaux', 'Diekirch', 'Echternach', 'Esch-sur-Alzette', 'Grevenmacher', 'Luxembourg', 'Mersch', 'Redange', 'Remich', 'Vianden', 'Wiltz'],
        'Madagascar' => ['Antananarivo', 'Antsiranana', 'Fianarantsoa', 'Mahajanga', 'Toamasina', 'Toliara'],
        'Malawi' => ['Balaka', 'Blantyre', 'Chikwawa', 'Chiradzulu', 'Chitipa', 'Dedza', 'Dowa', 'Karonga', 'Kasungu', 'Likoma', 'Lilongwe', 'Machinga', 'Mangochi', 'Mchinji', 'Mulanje', 'Mwanza', 'Mzimba', 'Neno', 'Nkhata Bay', 'Nkhotakota', 'Nsanje', 'Ntcheu', 'Ntchisi', 'Phalombe', 'Rumphi', 'Salima', 'Thyolo', 'Zomba'],
        'Malaysia' => ['Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis', 'Pulau Pinang', 'Sabah', 'Sarawak', 'Selangor', 'Terengganu', 'Wilayah Persekutuan Kuala Lumpur', 'Wilayah Persekutuan Labuan', 'Wilayah Persekutuan Putrajaya'],
        'Maldives' => ['Addu City', 'Faadhippolhu', 'Felidhu Atoll', 'Fuvammulah', 'Hahdhunmathi', 'Kolhumadulu', 'Male', 'Male Atoll', 'Mulaku Atoll', 'North Ari Atoll', 'North Huvadhu Atoll', 'North Maalhosmadulu', 'North Miladhunmadulu', 'North Nilandhe Atoll', 'North Thiladhunmathi', 'South Ari Atoll', 'South Huvadhu Atoll', 'South Maalhosmadulu', 'South Miladhunmadulu', 'South Nilandhe Atoll', 'South Thiladhunmathi'],
        'Mali' => ['Bamako', 'Gao', 'Kayes', 'Kidal', 'Koulikoro', 'Mopti', 'Ménaka', 'Sikasso', 'Ségou', 'Taoudénit', 'Tombouctou'],
        'Malta' => ['Attard', 'Balzan', 'Birgu', 'Birkirkara', 'Birżebbuġa', 'Bormla', 'Dingli', 'Fgura', 'Floriana', 'Fontana', 'Gudja', 'Għajnsielem', 'Għarb', 'Għargħur', 'Għasri', 'Għaxaq', 'Gżira', 'Iklin', 'Isla', 'Kalkara', 'Kerċem', 'Kirkop', 'Lija', 'Luqa', 'Marsa', 'Marsaskala', 'Marsaxlokk', 'Mdina', 'Mellieħa', 'Mosta', 'Mqabba', 'Msida', 'Mtarfa', 'Munxar', 'Mġarr', 'Nadur', 'Naxxar', 'Paola', 'Pembroke', 'Pietà', 'Qala', 'Qormi', 'Qrendi', 'Rabat Gozo', 'Rabat Malta', 'Safi', 'Saint John', 'Saint Julian\'s', 'Saint Lawrence', 'Saint Lucia\'s', 'Saint Paul\'s Bay', 'Sannat', 'Santa Venera', 'Siġġiewi', 'Sliema', 'Swieqi', 'Ta\' Xbiex', 'Tarxien', 'Valletta', 'Xagħra', 'Xewkija', 'Xgħajra', 'Ħamrun', 'Żabbar', 'Żebbuġ Gozo', 'Żebbuġ Malta', 'Żejtun', 'Żurrieq'],
        'Marshall Islands' => ['Ailinglaplap', 'Ailuk', 'Arno', 'Aur', 'Bikini & Kili', 'Ebon', 'Enewetak & Ujelang', 'Jabat', 'Jaluit', 'Kwajalein', 'Lae', 'Lib', 'Likiep', 'Majuro', 'Maloelap', 'Mejit', 'Mili', 'Namdrik', 'Namu', 'Rongelap', 'Ujae', 'Utrik', 'Wotho', 'Wotje'],
        'Mauritania' => ['Adrar', 'Assaba', 'Brakna', 'Dakhlet Nouâdhibou', 'Gorgol', 'Guidimaka', 'Hodh ech Chargui', 'Hodh el Gharbi', 'Inchiri', 'Nouakchott Nord', 'Nouakchott Ouest', 'Nouakchott Sud', 'Tagant', 'Tiris Zemmour', 'Trarza'],
        'Mauritius' => ['Agalega Islands', 'Black River', 'Cargados Carajos Shoals', 'Flacq', 'Grand Port', 'Moka', 'Pamplemousses', 'Plaines Wilhems', 'Port Louis', 'Rivière du Rempart', 'Rodrigues Island', 'Savanne'],
        'Mexico' => ['Aguascalientes', 'Baja California', 'Baja California Sur', 'Campeche', 'Chiapas', 'Chihuahua', 'Ciudad de México', 'Coahuila de Zaragoza', 'Colima', 'Durango', 'Guanajuato', 'Guerrero', 'Hidalgo', 'Jalisco', 'Michoacán de Ocampo', 'Morelos', 'México', 'Nayarit', 'Nuevo León', 'Oaxaca', 'Puebla', 'Querétaro', 'Quintana Roo', 'San Luis Potosí', 'Sinaloa', 'Sonora', 'Tabasco', 'Tamaulipas', 'Tlaxcala', 'Veracruz de Ignacio de la Llave', 'Yucatán', 'Zacatecas'],
        'Micronesia' => ['Chuuk', 'Kosrae', 'Pohnpei', 'Yap'],
        'Moldova' => ['Anenii Noi', 'Basarabeasca', 'Bender', 'Briceni', 'Bălți', 'Cahul', 'Cantemir', 'Chișinău', 'Cimișlia', 'Criuleni', 'Călărași', 'Căușeni', 'Dondușeni', 'Drochia', 'Dubăsari', 'Edineț', 'Florești', 'Fălești', 'Glodeni', 'Găgăuzia, Unitatea teritorială autonomă (UTAG)', 'Hîncești', 'Ialoveni', 'Leova', 'Nisporeni', 'Ocnița', 'Orhei', 'Rezina', 'Rîșcani', 'Soroca', 'Strășeni', 'Stînga Nistrului, unitatea teritorială din', 'Sîngerei', 'Taraclia', 'Telenești', 'Ungheni', 'Șoldănești', 'Ștefan Vodă'],
        'Monaco' => ['Fontvieille', 'Jardin Exotique', 'La Colle', 'La Condamine', 'La Gare', 'La Source', 'Larvotto', 'Malbousquet', 'Monaco-Ville', 'Moneghetti', 'Monte-Carlo', 'Moulins', 'Port-Hercule', 'Saint-Roman', 'Sainte-Dévote', 'Spélugues', 'Vallon de la Rousse'],
        'Mongolia' => ['Arhangay', 'Bayan-Ölgiy', 'Bayanhongor', 'Bulgan', 'Darhan uul', 'Dornod', 'Dornogovĭ', 'Dundgovĭ', 'Dzavhan', 'Govĭ-Altay', 'Govĭ-Sümber', 'Hentiy', 'Hovd', 'Hövsgöl', 'Orhon', 'Selenge', 'Sühbaatar', 'Töv', 'Ulaanbaatar', 'Uvs', 'Ömnögovĭ', 'Övörhangay'],
        'Montenegro' => ['Andrijevica', 'Bar', 'Berane', 'Bijelo Polje', 'Budva', 'Cetinje', 'Danilovgrad', 'Gusinje', 'Herceg-Novi', 'Kolašin', 'Kotor', 'Mojkovac', 'Nikšić', 'Petnjica', 'Plav', 'Pljevlja', 'Plužine', 'Podgorica', 'Rožaje', 'Tivat', 'Tuzi', 'Ulcinj', 'Zeta', 'Šavnik', 'Žabljak'],
        'Morocco' => ['Béni Mellal-Khénifra', 'Casablanca-Settat', 'Dakhla-Oued Ed-Dahab (EH)', 'Drâa-Tafilalet', 'Fès-Meknès', 'Guelmim-Oued Noun', 'L\'Oriental', 'Laâyoune-Sakia El Hamra', 'Marrakech-Safi', 'Rabat-Salé-Kénitra', 'Souss-Massa', 'Tanger-Tétouan-Al Hoceïma'],
        'Mozambique' => ['Cabo Delgado', 'Gaza', 'Inhambane', 'Manica', 'Maputo', 'Nampula', 'Niassa', 'Sofala', 'Tete', 'Zambézia'],
        'Myanmar' => ['Ayeyarwady', 'Bago', 'Chin', 'Kachin', 'Kayah', 'Kayin', 'Magway', 'Mandalay', 'Mon', 'Nay Pyi Taw', 'Rakhine', 'Sagaing', 'Shan', 'Tanintharyi', 'Yangon'],
        'Namibia' => ['//Karas', 'Erongo', 'Hardap', 'Kavango East', 'Kavango West', 'Khomas', 'Kunene', 'Ohangwena', 'Omaheke', 'Omusati', 'Oshana', 'Oshikoto', 'Otjozondjupa', 'Zambezi'],
        'Nauru' => ['Aiwo', 'Anabar', 'Anetan', 'Anibare', 'Baitsi', 'Boe', 'Buada', 'Denigomodu', 'Ewa', 'Ijuw', 'Meneng', 'Nibok', 'Uaboe', 'Yaren'],
        'Nepal' => ['Bagmati', 'Gandaki', 'Karnali', 'Koshi', 'Lumbini', 'Madhesh', 'Sudurpashchim'],
        'Netherlands' => ['Aruba', 'Bonaire', 'Curaçao', 'Drenthe', 'Flevoland', 'Fryslân', 'Gelderland', 'Groningen', 'Limburg', 'Noord-Brabant', 'Noord-Holland', 'Overijssel', 'Saba', 'Sint Eustatius', 'Sint Maarten', 'Utrecht', 'Zeeland', 'Zuid-Holland'],
        'New Zealand' => ['Auckland', 'Bay of Plenty', 'Canterbury', 'Chatham Islands Territory', 'Gisborne', 'Greater Wellington', 'Hawke\'s Bay', 'Manawatū-Whanganui', 'Marlborough', 'Nelson', 'Northland', 'Otago', 'Southland', 'Taranaki', 'Tasman', 'Waikato', 'West Coast'],
        'Nicaragua' => ['Boaco', 'Carazo', 'Chinandega', 'Chontales', 'Costa Caribe Norte', 'Costa Caribe Sur', 'Estelí', 'Granada', 'Jinotega', 'León', 'Madriz', 'Managua', 'Masaya', 'Matagalpa', 'Nueva Segovia', 'Rivas', 'Río San Juan'],
        'Niger' => ['Agadez', 'Diffa', 'Dosso', 'Maradi', 'Niamey', 'Tahoua', 'Tillabéri', 'Zinder'],
        'Nigeria' => ['Abia', 'Abuja Federal Capital Territory', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara'],
        'North Macedonia' => ['Aerodrom †', 'Aračinovo', 'Berovo', 'Bitola', 'Bogdanci', 'Bogovinje', 'Bosilovo', 'Brvenica', 'Butel †', 'Centar Župa', 'Centar †', 'Debar', 'Debrca', 'Delčevo', 'Demir Hisar', 'Demir Kapija', 'Dojran', 'Dolneni', 'Gazi Baba †', 'Gevgelija', 'Gjorče Petrov †', 'Gostivar', 'Gradsko', 'Ilinden', 'Jegunovce', 'Karbinci', 'Karpoš †', 'Kavadarci', 'Kisela Voda †', 'Kičevo', 'Konče', 'Kočani', 'Kratovo', 'Kriva Palanka', 'Krivogaštani', 'Kruševo', 'Kumanovo', 'Lipkovo', 'Lozovo', 'Makedonska Kamenica', 'Makedonski Brod', 'Mavrovo i Rostuše', 'Mogila', 'Negotino', 'Novaci', 'Novo Selo', 'Ohrid', 'Pehčevo', 'Petrovec', 'Plasnica', 'Prilep', 'Probištip', 'Radoviš', 'Rankovce', 'Resen', 'Rosoman', 'Saraj †', 'Sopište', 'Staro Nagoričane', 'Struga', 'Strumica', 'Studeničani', 'Sveti Nikole', 'Tearce', 'Tetovo', 'Valandovo', 'Vasilevo', 'Veles', 'Vevčani', 'Vinica', 'Vrapčište', 'Zelenikovo', 'Zrnovci', 'Čair †', 'Čaška', 'Češinovo-Obleševo', 'Čučer-Sandevo', 'Štip', 'Šuto Orizari †', 'Želino'],
        'Norway' => ['Agder', 'Innlandet', 'Jan Mayen (Arctic Region)', 'Møre og Romsdal', 'Nordland', 'Oslo', 'Rogaland', 'Romssa ja Finnmárkku', 'Svalbard (Arctic Region)', 'Trööndelage', 'Vestfold og Telemark', 'Vestland', 'Viken'],
        'Oman' => ['Ad Dākhilīyah', 'Al Buraymī', 'Al Wusţá', 'Az̧ Z̧āhirah', 'Janūb al Bāţinah', 'Janūb ash Sharqīyah', 'Masqaţ', 'Musandam', 'Shamāl al Bāţinah', 'Shamāl ash Sharqīyah', 'Z̧ufār'],
        'Pakistan' => ['Azad Jammu and Kashmir', 'Balochistan', 'Gilgit-Baltistan', 'Islamabad', 'Khyber Pakhtunkhwa', 'Punjab', 'Sindh'],
        'Palau' => ['Aimeliik', 'Airai', 'Angaur', 'Hatohobei', 'Kayangel', 'Koror', 'Melekeok', 'Ngaraard', 'Ngarchelong', 'Ngardmau', 'Ngatpang', 'Ngchesar', 'Ngeremlengui', 'Ngiwal', 'Peleliu', 'Sonsorol'],
        'Palestine' => ['Bethlehem', 'Deir El Balah', 'Gaza', 'Hebron', 'Jenin', 'Jericho and Al Aghwar', 'Jerusalem', 'Khan Yunis', 'Nablus', 'North Gaza', 'Qalqilya', 'Rafah', 'Ramallah', 'Salfit', 'Tubas', 'Tulkarm'],
        'Panama' => ['Bocas del Toro', 'Chiriquí', 'Coclé', 'Colón', 'Darién', 'Emberá', 'Guna Yala', 'Herrera', 'Los Santos', 'Naso Tjër Di', 'Ngäbe-Buglé', 'Panamá', 'Panamá Oeste', 'Veraguas'],
        'Papua New Guinea' => ['Bougainville', 'Central', 'Chimbu', 'East New Britain', 'East Sepik', 'Eastern Highlands', 'Enga', 'Gulf', 'Hela', 'Jiwaka', 'Madang', 'Manus', 'Milne Bay', 'Morobe', 'National Capital District (Port Moresby)', 'New Ireland', 'Northern', 'Southern Highlands', 'West New Britain', 'West Sepik', 'Western', 'Western Highlands'],
        'Paraguay' => ['Alto Paraguay', 'Alto Paraná', 'Amambay', 'Asunción', 'Boquerón', 'Caaguazú', 'Caazapá', 'Canindeyú', 'Central', 'Concepción', 'Cordillera', 'Guairá', 'Itapúa', 'Misiones', 'Paraguarí', 'Presidente Hayes', 'San Pedro', 'Ñeembucú'],
        'Peru' => ['Amarumayu', 'Ancash', 'Apurimaq', 'Arequipa', 'Ayacucho', 'Cajamarca', 'Cusco', 'El Callao', 'Huancavelica', 'Hunin', 'Huánuco', 'Ica', 'La Libertad', 'Lambayeque', 'Lima', 'Lima hatun llaqta', 'Loreto', 'Madre de Dios', 'Moquegua', 'Pasco', 'Piura', 'Puno', 'San Martin', 'Tacna', 'Tumbes', 'Ucayali'],
        'Philippines' => ['Abra', 'Agusan del Norte', 'Agusan del Sur', 'Aklan', 'Albay', 'Antique', 'Apayao', 'Aurora', 'Basilan', 'Bataan', 'Batanes', 'Batangas', 'Benguet', 'Biliran', 'Bohol', 'Bukidnon', 'Bulacan', 'Cagayan', 'Camarines Norte', 'Camarines Sur', 'Camiguin', 'Capiz', 'Catanduanes', 'Cavite', 'Cebu', 'Cotabato', 'Davao Occidental', 'Davao Oriental', 'Davao de Oro', 'Davao del Norte', 'Davao del Sur', 'Dinagat Islands', 'Eastern Samar', 'Guimaras', 'Ifugao', 'Ilocos Norte', 'Ilocos Sur', 'Iloilo', 'Isabela', 'Kalinga', 'La Union', 'Laguna', 'Lanao del Norte', 'Lanao del Sur', 'Leyte', 'Maguindanao del Norte', 'Maguindanao del Sur', 'Marinduque', 'Masbate', 'Mindoro Occidental', 'Mindoro Oriental', 'Misamis Occidental', 'Misamis Oriental', 'Mountain Province', 'Negros Occidental', 'Negros Oriental', 'Northern Samar', 'Nueva Ecija', 'Nueva Vizcaya', 'Palawan', 'Pampanga', 'Pangasinan', 'Quezon', 'Quirino', 'Rizal', 'Romblon', 'Samar', 'Sarangani', 'Siquijor', 'Sorsogon', 'South Cotabato', 'Southern Leyte', 'Sultan Kudarat', 'Sulu', 'Surigao del Norte', 'Surigao del Sur', 'Tarlac', 'Tawi-Tawi', 'Zambales', 'Zamboanga Sibugay', 'Zamboanga del Norte', 'Zamboanga del Sur'],
        'Poland' => ['Dolnośląskie', 'Kujawsko-Pomorskie', 'Lubelskie', 'Lubuskie', 'Mazowieckie', 'Małopolskie', 'Opolskie', 'Podkarpackie', 'Podlaskie', 'Pomorskie', 'Warmińsko-Mazurskie', 'Wielkopolskie', 'Zachodniopomorskie', 'Łódzkie', 'Śląskie', 'Świętokrzyskie'],
        'Portugal' => ['Aveiro', 'Beja', 'Braga', 'Bragança', 'Castelo Branco', 'Coimbra', 'Faro', 'Guarda', 'Leiria', 'Lisboa', 'Portalegre', 'Porto', 'Região Autónoma da Madeira', 'Região Autónoma dos Açores', 'Santarém', 'Setúbal', 'Viana do Castelo', 'Vila Real', 'Viseu', 'Évora'],
        'Qatar' => ['Ad Dawḩah', 'Al Khawr wa adh Dhakhīrah', 'Al Wakrah', 'Ar Rayyān', 'Ash Shamāl', 'Ash Shīḩānīyah', 'Az̧ Z̧a‘āyin', 'Umm Şalāl'],
        'Romania' => ['Alba', 'Arad', 'Argeș', 'Bacău', 'Bihor', 'Bistrița-Năsăud', 'Botoșani', 'Brașov', 'Brăila', 'București', 'Buzău', 'Caraș-Severin', 'Cluj', 'Constanța', 'Covasna', 'Călărași', 'Dolj', 'Dâmbovița', 'Galați', 'Giurgiu', 'Gorj', 'Harghita', 'Hunedoara', 'Ialomița', 'Iași', 'Ilfov', 'Maramureș', 'Mehedinți', 'Mureș', 'Neamț', 'Olt', 'Prahova', 'Satu Mare', 'Sibiu', 'Suceava', 'Sălaj', 'Teleorman', 'Timiș', 'Tulcea', 'Vaslui', 'Vrancea', 'Vâlcea'],
        'Russia' => ['Adygeja, Respublika', 'Altaj, Respublika', 'Altajskij kraj', 'Amurskaja oblast\'', 'Arhangel\'skaja oblast\'', 'Astrahanskaja oblast\'', 'Bashkortostan, Respublika', 'Belgorodskaja oblast\'', 'Brjanskaja oblast\'', 'Burjatija, Respublika', 'Chechenskaya Respublika', 'Chelyabinskaya oblast\'', 'Chukotskiy avtonomnyy okrug', 'Chuvashskaya Respublika', 'Dagestan, Respublika', 'Evrejskaja avtonomnaja oblast\'', 'Habarovskij kraj', 'Hakasija, Respublika', 'Hanty-Mansijskij avtonomnyj okrug', 'Ingushetiya, Respublika', 'Irkutskaja oblast\'', 'Ivanovskaja oblast\'', 'Jamalo-Neneckij avtonomnyj okrug', 'Jaroslavskaja oblast\'', 'Kabardino-Balkarskaja Respublika', 'Kaliningradskaja oblast\'', 'Kalmykija, Respublika', 'Kaluzhskaya oblast\'', 'Kamchatskiy kray', 'Karachayevo-Cherkesskaya Respublika', 'Karelija, Respublika', 'Kemerovskaja oblast\'', 'Kirovskaja oblast\'', 'Komi, Respublika', 'Kostromskaja oblast\'', 'Krasnodarskij kraj', 'Krasnojarskij kraj', 'Kurganskaja oblast\'', 'Kurskaja oblast\'', 'Leningradskaja oblast\'', 'Lipeckaja oblast\'', 'Magadanskaja oblast\'', 'Marij Èl, Respublika', 'Mordovija, Respublika', 'Moskovskaja oblast\'', 'Moskva', 'Murmanskaja oblast\'', 'Neneckij avtonomnyj okrug', 'Nizhegorodskaya oblast\'', 'Novgorodskaja oblast\'', 'Novosibirskaja oblast\'', 'Omskaja oblast\'', 'Orenburgskaja oblast\'', 'Orlovskaja oblast\'', 'Penzenskaja oblast\'', 'Permskij kraj', 'Primorskij kraj', 'Pskovskaja oblast\'', 'Rjazanskaja oblast\'', 'Rostovskaja oblast\'', 'Saha, Respublika', 'Sahalinskaja oblast\'', 'Samarskaja oblast\'', 'Sankt-Peterburg', 'Saratovskaja oblast\'', 'Severnaja Osetija, Respublika', 'Smolenskaja oblast\'', 'Stavropol\'skij kraj', 'Sverdlovskaja oblast\'', 'Tambovskaja oblast\'', 'Tatarstan, Respublika', 'Tjumenskaja oblast\'', 'Tomskaja oblast\'', 'Tul\'skaja oblast\'', 'Tverskaja oblast\'', 'Tyva, Respublika', 'Udmurtskaja Respublika', 'Ul\'janovskaja oblast\'', 'Vladimirskaja oblast\'', 'Volgogradskaja oblast\'', 'Vologodskaja oblast\'', 'Voronezhskaya oblast\'', 'Zabajkal\'skij kraj'],
        'Rwanda' => ['Bugesera', 'Burera', 'Gakenke', 'Gasabo', 'Gatsibo', 'Gicumbi', 'Gisagara', 'Huye', 'Kamonyi', 'Karongi', 'Kayonza', 'Kicukiro', 'Kigali', 'Kirehe', 'Muhanga', 'Musanze', 'Ngoma', 'Ngororero', 'Nyabihu', 'Nyagatare', 'Nyamagabe', 'Nyamasheke', 'Nyanza', 'Nyarugenge', 'Nyaruguru', 'Rubavu', 'Ruhango', 'Rulindo', 'Rusizi', 'Rutsiro', 'Rwamagana'],
        'Saint Kitts and Nevis' => ['Christ Church Nichola Town', 'Saint Anne Sandy Point', 'Saint George Basseterre', 'Saint George Gingerland', 'Saint James Windward', 'Saint John Capisterre', 'Saint John Figtree', 'Saint Mary Cayon', 'Saint Paul Capisterre', 'Saint Paul Charlestown', 'Saint Peter Basseterre', 'Saint Thomas Lowland', 'Saint Thomas Middle Island', 'Trinity Palmetto Point'],
        'Saint Lucia' => ['Anse la Raye', 'Canaries', 'Castries', 'Choiseul', 'Dennery', 'Gros Islet', 'Laborie', 'Micoud', 'Soufrière', 'Vieux Fort'],
        'Saint Vincent and the Grenadines' => ['Charlotte', 'Grenadines', 'Saint Andrew', 'Saint David', 'Saint George', 'Saint Patrick'],
        'Samoa' => ['A\'ana', 'Aiga-i-le-Tai', 'Atua', 'Fa\'asaleleaga', 'Gaga\'emauga', 'Gagaifomauga', 'Palauli', 'Satupa\'itea', 'Tuamasaga', 'Va\'a-o-Fonoti', 'Vaisigano'],
        'San Marino' => ['Acquaviva', 'Borgo Maggiore', 'Chiesanuova', 'Città di San Marino', 'Domagnano', 'Faetano', 'Fiorentino', 'Montegiardino', 'Serravalle'],
        'Sao Tome and Principe' => ['Cantagalo', 'Caué', 'Lembá', 'Lobata', 'Mé-Zóchi', 'Príncipe', 'Água Grande'],
        'Saudi Arabia' => ['\'Asīr', 'Al Bāḩah', 'Al Jawf', 'Al Madīnah al Munawwarah', 'Al Qaşīm', 'Al Ḩudūd ash Shamālīyah', 'Ar Riyāḑ', 'Ash Sharqīyah', 'Jāzān', 'Makkah al Mukarramah', 'Najrān', 'Tabūk', 'Ḩā\'il'],
        'Senegal' => ['Dakar', 'Diourbel', 'Fatick', 'Kaffrine', 'Kaolack', 'Kolda', 'Kédougou', 'Louga', 'Matam', 'Saint-Louis', 'Sédhiou', 'Tambacounda', 'Thiès', 'Ziguinchor'],
        'Serbia' => ['Beograd', 'Borski okrug', 'Braničevski okrug', 'Jablanički okrug', 'Kolubarski okrug', 'Kosovo-Metohija', 'Mačvanski okrug', 'Moravički okrug', 'Nišavski okrug', 'Pirotski okrug', 'Podunavski okrug', 'Pomoravski okrug', 'Pčinjski okrug', 'Rasinski okrug', 'Raški okrug', 'Toplički okrug', 'Vojvodina', 'Zaječarski okrug', 'Zlatiborski okrug', 'Šumadijski okrug'],
        'Seychelles' => ['Anse Boileau', 'Anse Etoile', 'Anse Royale', 'Anse aux Pins', 'Au Cap', 'Baie Lazare', 'Baie Sainte Anne', 'Beau Vallon', 'Bel Air', 'Bel Ombre', 'Cascade', 'English River', 'Glacis', 'Grand Anse Mahe', 'Grand Anse Praslin', 'Ile Perseverance I', 'Ile Perseverance II', 'La Digue', 'Les Mamelles', 'Mont Buxton', 'Mont Fleuri', 'Plaisance', 'Pointe Larue', 'Port Glaud', 'Roche Caiman', 'Saint Louis', 'Takamaka'],
        'Sierra Leone' => ['Eastern', 'North Western', 'Northern', 'Southern', 'Western Area (Freetown)'],
        'Singapore' => ['Central Singapore', 'North East', 'North West', 'South East', 'South West'],
        'Slovakia' => ['Banskobystrický kraj', 'Bratislavský kraj', 'Košický kraj', 'Nitriansky kraj', 'Prešovský kraj', 'Trenčiansky kraj', 'Trnavský kraj', 'Žilinský kraj'],
        'Slovenia' => ['Ajdovščina', 'Ankaran', 'Apače', 'Beltinci', 'Benedikt', 'Bistrica ob Sotli', 'Bled', 'Bloke', 'Bohinj', 'Borovnica', 'Bovec', 'Braslovče', 'Brda', 'Brezovica', 'Brežice', 'Cankova', 'Celje', 'Cerklje na Gorenjskem', 'Cerknica', 'Cerkno', 'Cerkvenjak', 'Cirkulane', 'Destrnik', 'Divača', 'Dobje', 'Dobrepolje', 'Dobrna', 'Dobrova-Polhov Gradec', 'Dobrovnik', 'Dol pri Ljubljani', 'Dolenjske Toplice', 'Domžale', 'Dornava', 'Dravograd', 'Duplek', 'Gorenja vas-Poljane', 'Gorišnica', 'Gorje', 'Gornja Radgona', 'Gornji Grad', 'Gornji Petrovci', 'Grad', 'Grosuplje', 'Hajdina', 'Hodoš', 'Horjul', 'Hoče-Slivnica', 'Hrastnik', 'Hrpelje-Kozina', 'Idrija', 'Ig', 'Ilirska Bistrica', 'Ivančna Gorica', 'Izola', 'Jesenice', 'Jezersko', 'Juršinci', 'Kamnik', 'Kanal ob Soči', 'Kidričevo', 'Kobarid', 'Kobilje', 'Komen', 'Komenda', 'Koper', 'Kostanjevica na Krki', 'Kostel', 'Kozje', 'Kočevje', 'Kranj', 'Kranjska Gora', 'Križevci', 'Krško', 'Kungota', 'Kuzma', 'Laško', 'Lenart', 'Lendava', 'Litija', 'Ljubljana', 'Ljubno', 'Ljutomer', 'Log-Dragomer', 'Logatec', 'Lovrenc na Pohorju', 'Loška dolina', 'Loški Potok', 'Lukovica', 'Luče', 'Majšperk', 'Makole', 'Maribor', 'Markovci', 'Medvode', 'Mengeš', 'Metlika', 'Mežica', 'Miklavž na Dravskem polju', 'Miren-Kostanjevica', 'Mirna', 'Mirna Peč', 'Mislinja', 'Mokronog-Trebelno', 'Moravske Toplice', 'Moravče', 'Mozirje', 'Murska Sobota', 'Muta', 'Naklo', 'Nazarje', 'Nova Gorica', 'Novo Mesto', 'Odranci', 'Oplotnica', 'Ormož', 'Osilnica', 'Pesnica', 'Piran', 'Pivka', 'Podlehnik', 'Podvelka', 'Podčetrtek', 'Poljčane', 'Polzela', 'Postojna', 'Prebold', 'Preddvor', 'Prevalje', 'Ptuj', 'Puconci', 'Radenci', 'Radeče', 'Radlje ob Dravi', 'Radovljica', 'Ravne na Koroškem', 'Razkrižje', 'Rače-Fram', 'Renče-Vogrsko', 'Rečica ob Savinji', 'Ribnica', 'Ribnica na Pohorju', 'Rogatec', 'Rogaška Slatina', 'Rogašovci', 'Ruše', 'Selnica ob Dravi', 'Semič', 'Sevnica', 'Sežana', 'Slovenj Gradec', 'Slovenska Bistrica', 'Slovenske Konjice', 'Sodražica', 'Solčava', 'Središče ob Dravi', 'Starše', 'Straža', 'Sveta Ana', 'Sveta Trojica v Slovenskih goricah', 'Sveti Andraž v Slovenskih goricah', 'Sveti Jurij ob Ščavnici', 'Sveti Jurij v Slovenskih goricah', 'Sveti Tomaž', 'Tabor', 'Tišina', 'Tolmin', 'Trbovlje', 'Trebnje', 'Trnovska Vas', 'Trzin', 'Tržič', 'Turnišče', 'Velenje', 'Velika Polana', 'Velike Lašče', 'Veržej', 'Videm', 'Vipava', 'Vitanje', 'Vodice', 'Vojnik', 'Vransko', 'Vrhnika', 'Vuzenica', 'Zagorje ob Savi', 'Zavrč', 'Zreče', 'Črenšovci', 'Črna na Koroškem', 'Črnomelj', 'Šalovci', 'Šempeter-Vrtojba', 'Šentilj', 'Šentjernej', 'Šentjur', 'Šentrupert', 'Šenčur', 'Škocjan', 'Škofja Loka', 'Škofljica', 'Šmarje pri Jelšah', 'Šmarješke Toplice', 'Šmartno ob Paki', 'Šmartno pri Litiji', 'Šoštanj', 'Štore', 'Žalec', 'Železniki', 'Žetale', 'Žiri', 'Žirovnica', 'Žužemberk'],
        'Solomon Islands' => ['Capital Territory (Honiara)', 'Central', 'Choiseul', 'Guadalcanal', 'Isabel', 'Makira-Ulawa', 'Malaita', 'Rennell and Bellona', 'Temotu', 'Western'],
        'Somalia' => ['Awdal', 'Bakool', 'Banaadir', 'Bari', 'Bay', 'Galguduud', 'Gedo', 'Hiiraan', 'Jubbada Dhexe', 'Jubbada Hoose', 'Mudug', 'Nugaal', 'Sanaag', 'Shabeellaha Dhexe', 'Shabeellaha Hoose', 'Sool', 'Togdheer', 'Woqooyi Galbeed'],
        'South Africa' => ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape'],
        'South Sudan' => ['Central Equatoria', 'Eastern Equatoria', 'Jonglei', 'Lakes', 'Northern Bahr el Ghazal', 'Unity', 'Upper Nile', 'Warrap', 'Western Bahr el Ghazal', 'Western Equatoria'],
        'Spain' => ['A Coruña', 'Alacant*', 'Albacete', 'Almería', 'Araba*', 'Asturias', 'Badajoz', 'Barcelona', 'Bizkaia', 'Burgos', 'Cantabria', 'Castelló*', 'Ciudad Real', 'Cuenca', 'Cáceres', 'Cádiz', 'Córdoba', 'Gipuzkoa', 'Girona', 'Granada', 'Guadalajara', 'Huelva', 'Huesca', 'Illes Balears', 'Jaén', 'La Rioja', 'Las Palmas', 'León', 'Lleida', 'Lugo', 'Madrid', 'Murcia', 'Málaga', 'Nafarroa*', 'Ourense', 'Palencia', 'Pontevedra', 'Salamanca', 'Santa Cruz de Tenerife', 'Segovia', 'Sevilla', 'Soria', 'Tarragona', 'Teruel', 'Toledo', 'Valencia', 'Valladolid', 'Zamora', 'Zaragoza', 'Ávila'],
        'Sri Lanka' => ['Ampara', 'Anuradhapura', 'Badulla', 'Batticaloa', 'Colombo', 'Galle', 'Gampaha', 'Hambantota', 'Jaffna', 'Kalutara', 'Kandy', 'Kegalla', 'Kilinochchi', 'Kurunegala', 'Mannar', 'Matale', 'Matara', 'Monaragala', 'Mullaittivu', 'Nuwara Eliya', 'Polonnaruwa', 'Puttalam', 'Ratnapura', 'Trincomalee', 'Vavuniya'],
        'Sudan' => ['Blue Nile', 'Central Darfur', 'East Darfur', 'Gedaref', 'Gezira', 'Kassala', 'Khartoum', 'North Darfur', 'North Kordofan', 'Northern', 'Red Sea', 'River Nile', 'Sennar', 'South Darfur', 'South Kordofan', 'West Darfur', 'West Kordofan', 'White Nile'],
        'Suriname' => ['Brokopondo', 'Commewijne', 'Coronie', 'Marowijne', 'Nickerie', 'Para', 'Paramaribo', 'Saramacca', 'Sipaliwini', 'Wanica'],
        'Sweden' => ['Blekinge län', 'Dalarnas län', 'Gotlands län', 'Gävleborgs län', 'Hallands län', 'Jämtlands län', 'Jönköpings län', 'Kalmar län', 'Kronobergs län', 'Norrbottens län', 'Skåne län', 'Stockholms län', 'Södermanlands län', 'Uppsala län', 'Värmlands län', 'Västerbottens län', 'Västernorrlands län', 'Västmanlands län', 'Västra Götalands län', 'Örebro län', 'Östergötlands län'],
        'Switzerland' => ['Aargau', 'Appenzell Ausserrhoden', 'Appenzell Innerrhoden', 'Basel-Landschaft', 'Basel-Stadt', 'Berne', 'Fribourg', 'Genève', 'Glarus', 'Graubünden', 'Jura', 'Luzern', 'Neuchâtel', 'Nidwalden', 'Obwalden', 'Sankt Gallen', 'Schaffhausen', 'Schwyz', 'Solothurn', 'Thurgau', 'Ticino', 'Uri', 'Valais', 'Vaud', 'Zug', 'Zürich'],
        'Syria' => ['Al Lādhiqīyah', 'Al Qunayţirah', 'Al Ḩasakah', 'Ar Raqqah', 'As Suwaydā\'', 'Dar\'ā', 'Dayr az Zawr', 'Dimashq', 'Idlib', 'Rīf Dimashq', 'Ţarţūs', 'Ḩalab', 'Ḩamāh', 'Ḩimş'],
        'Taiwan' => ['Changhua', 'Chiayi', 'Hsinchu', 'Hualien', 'Kaohsiung', 'Keelung', 'Kinmen', 'Lienchiang', 'Miaoli', 'Nantou', 'New Taipei', 'Penghu', 'Pingtung', 'Taichung', 'Tainan', 'Taipei', 'Taitung', 'Taoyuan', 'Yilan', 'Yunlin'],
        'Tajikistan' => ['Dushanbe', 'Khatlon', 'Kŭhistoni Badakhshon', 'Sughd', 'nohiyahoi tobei jumhurí'],
        'Tanzania' => ['Arusha', 'Arusha Rural', 'Babati', 'Bagamoyo', 'Bariadi', 'Biharamulo', 'Bukoba', 'Bukombe', 'Bunda', 'Chalinze', 'Chamwino', 'Chato', 'Chunya', 'Dar es Salaam', 'Dodoma', 'Geita', 'Hai', 'Handeni', 'Igunga', 'Ikungi', 'Ilala', 'Ilemela', 'Iringa', 'Itilima', 'Kagera', 'Kahama', 'Kakonko', 'Kalambo', 'Kaliua', 'Karagwe', 'Karatu', 'Kasulu', 'Katavi', 'Kibaha', 'Kibondo', 'Kigamboni', 'Kigoma', 'Kilimanjaro', 'Kilindi', 'Kilolo', 'Kilombero', 'Kilosa', 'Kilwa', 'Kinondoni', 'Kisarawe', 'Kishapu', 'Kiteto', 'Kondoa', 'Kongwa', 'Korogwe', 'Kwimba', 'Kyela', 'Kyerwa', 'Lindi', 'Liwale', 'Longido', 'Ludewa', 'Lushoto', 'Mafia', 'Mafinga', 'Magu', 'Makambako', 'Makete', 'Manyara', 'Manyoni', 'Mara', 'Masasi', 'Maswa', 'Mbarali', 'Mbeya', 'Mbinga', 'Mbogwe', 'Mbozi', 'Mbulu', 'Meatu', 'Meru', 'Missenyi', 'Mkalama', 'Mkinga', 'Mkuranga', 'Mlele', 'Momba', 'Monduli', 'Morogoro', 'Moshi', 'Mpanda', 'Mpwapwa', 'Mtwara', 'Mufindi', 'Muheza', 'Muleba', 'Musoma', 'Mvomero', 'Mwanga', 'Mwanza', 'Nachingwea', 'Namtumbo', 'Nanyumbu', 'Newala', 'Ngara', 'Ngorongoro', 'Njombe', 'Nkasi', 'Nyamagana', 'Nyasa', 'Nzega', 'Pangani', 'Pemba North', 'Pemba South', 'Pwani', 'Rombo', 'Rorya', 'Ruangwa', 'Rufiji', 'Rukwa', 'Rungwe', 'Ruvuma', 'Same', 'Sengerema', 'Serengeti', 'Shinyanga', 'Siha', 'Sikonge', 'Simanjiro', 'Simiyu', 'Singida', 'Songea', 'Songwe', 'Sumbawanga', 'Tabora', 'Tandahimba', 'Tanga', 'Tarime', 'Temeke', 'Tunduma', 'Tunduru', 'Ubungo', 'Ukerewe', 'Ulanga', 'Urambo', 'Uvinza', 'Wanging\'ombe', 'Zanzibar', 'Zanzibar Central/South', 'Zanzibar North', 'Zanzibar Urban/West'],
        'Thailand' => ['Amnat Charoen', 'Ang Thong', 'Bueng Kan', 'Buri Ram', 'Chachoengsao', 'Chai Nat', 'Chaiyaphum', 'Chanthaburi', 'Chiang Mai', 'Chiang Rai', 'Chon Buri', 'Chumphon', 'Kalasin', 'Kamphaeng Phet', 'Kanchanaburi', 'Khon Kaen', 'Krabi', 'Krung Thep Maha Nakhon', 'Lampang', 'Lamphun', 'Loei', 'Lop Buri', 'Mae Hong Son', 'Maha Sarakham', 'Mukdahan', 'Nakhon Nayok', 'Nakhon Pathom', 'Nakhon Phanom', 'Nakhon Ratchasima', 'Nakhon Sawan', 'Nakhon Si Thammarat', 'Nan', 'Narathiwat', 'Nong Bua Lam Phu', 'Nong Khai', 'Nonthaburi', 'Pathum Thani', 'Pattani', 'Phangnga', 'Phatthalung', 'Phatthaya', 'Phayao', 'Phetchabun', 'Phetchaburi', 'Phichit', 'Phitsanulok', 'Phra Nakhon Si Ayutthaya', 'Phrae', 'Phuket', 'Prachin Buri', 'Prachuap Khiri Khan', 'Ranong', 'Ratchaburi', 'Rayong', 'Roi Et', 'Sa Kaeo', 'Sakon Nakhon', 'Samut Prakan', 'Samut Sakhon', 'Samut Songkhram', 'Saraburi', 'Satun', 'Si Sa Ket', 'Sing Buri', 'Songkhla', 'Sukhothai', 'Suphan Buri', 'Surat Thani', 'Surin', 'Tak', 'Trang', 'Trat', 'Ubon Ratchathani', 'Udon Thani', 'Uthai Thani', 'Uttaradit', 'Yala', 'Yasothon'],
        'Timor-Leste' => ['Aileu', 'Ainaro', 'Baucau', 'Bobonaro', 'Cova Lima', 'Díli', 'Ermera', 'Lautein', 'Likisá', 'Manatuto', 'Manufahi', 'Oekusi-Ambenu', 'Vikeke'],
        'Togo' => ['Centrale', 'Kara', 'Maritime (Région)', 'Plateaux', 'Savanes'],
        'Tonga' => ['\'Eua', 'Ha\'apai', 'Niuas', 'Tongatapu', 'Vava\'u'],
        'Trinidad and Tobago' => ['Arima', 'Chaguanas', 'Couva-Tabaquite-Talparo', 'Diego Martin', 'Mayaro-Rio Claro', 'Penal-Debe', 'Point Fortin', 'Port of Spain', 'Princes Town', 'San Fernando', 'San Juan-Laventille', 'Sangre Grande', 'Siparia', 'Tobago', 'Tunapuna-Piarco'],
        'Tunisia' => ['Ben Arous', 'Bizerte', 'Béja', 'Gabès', 'Gafsa', 'Jendouba', 'Kairouan', 'Kasserine', 'Kébili', 'L\'Ariana', 'La Manouba', 'Le Kef', 'Mahdia', 'Monastir', 'Médenine', 'Nabeul', 'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse', 'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan'],
        'Turkey' => ['Adana', 'Adıyaman', 'Afyonkarahisar', 'Aksaray', 'Amasya', 'Ankara', 'Antalya', 'Ardahan', 'Artvin', 'Aydın', 'Ağrı', 'Balıkesir', 'Bartın', 'Batman', 'Bayburt', 'Bilecik', 'Bingöl', 'Bitlis', 'Bolu', 'Burdur', 'Bursa', 'Denizli', 'Diyarbakır', 'Düzce', 'Edirne', 'Elazığ', 'Erzincan', 'Erzurum', 'Eskişehir', 'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkâri', 'Hatay', 'Isparta', 'Iğdır', 'Kahramanmaraş', 'Karabük', 'Karaman', 'Kars', 'Kastamonu', 'Kayseri', 'Kilis', 'Kocaeli', 'Konya', 'Kütahya', 'Kırklareli', 'Kırıkkale', 'Kırşehir', 'Malatya', 'Manisa', 'Mardin', 'Mersin', 'Muğla', 'Muş', 'Nevşehir', 'Niğde', 'Ordu', 'Osmaniye', 'Rize', 'Sakarya', 'Samsun', 'Siirt', 'Sinop', 'Sivas', 'Tekirdağ', 'Tokat', 'Trabzon', 'Tunceli', 'Uşak', 'Van', 'Yalova', 'Yozgat', 'Zonguldak', 'Çanakkale', 'Çankırı', 'Çorum', 'İstanbul', 'İzmir', 'Şanlıurfa', 'Şırnak'],
        'Turkmenistan' => ['Ahal', 'Aşgabat', 'Balkan', 'Daşoguz', 'Lebap', 'Mary'],
        'Tuvalu' => ['Funafuti', 'Nanumaga', 'Nanumea', 'Niutao', 'Nui', 'Nukufetau', 'Nukulaelae', 'Vaitupu'],
        'Uganda' => ['Abim', 'Adjumani', 'Agago', 'Alebtong', 'Amolatar', 'Amudat', 'Amuria', 'Amuru', 'Apac', 'Arua', 'Budaka', 'Bududa', 'Bugiri', 'Bugweri', 'Buhweju', 'Buikwe', 'Bukedea', 'Bukomansibi', 'Bukwo', 'Bulambuli', 'Buliisa', 'Bundibugyo', 'Bunyangabu', 'Bushenyi', 'Busia', 'Butaleja', 'Butambala', 'Butebo', 'Buvuma', 'Buyende', 'Dokolo', 'Gomba', 'Gulu', 'Hoima', 'Ibanda', 'Iganga', 'Isingiro', 'Jinja', 'Kaabong', 'Kabale', 'Kabarole', 'Kaberamaido', 'Kagadi', 'Kakumiro', 'Kalaki', 'Kalangala', 'Kaliro', 'Kalungu', 'Kampala', 'Kamuli', 'Kamwenge', 'Kanungu', 'Kapchorwa', 'Kapelebyong', 'Karenga', 'Kasanda', 'Kasese', 'Katakwi', 'Kayunga', 'Kazo', 'Kibaale', 'Kiboga', 'Kibuku', 'Kikuube', 'Kiruhura', 'Kiryandongo', 'Kisoro', 'Kitagwenda', 'Kitgum', 'Koboko', 'Kole', 'Kotido', 'Kumi', 'Kwania', 'Kween', 'Kyankwanzi', 'Kyegegwa', 'Kyenjojo', 'Kyotera', 'Lamwo', 'Lira', 'Luuka', 'Luwero', 'Lwengo', 'Lyantonde', 'Madi-Okollo', 'Manafwa', 'Maracha', 'Masaka', 'Masindi', 'Mayuge', 'Mbale', 'Mbarara', 'Mitooma', 'Mityana', 'Moroto', 'Moyo', 'Mpigi', 'Mubende', 'Mukono', 'Nabilatuk', 'Nakapiripirit', 'Nakaseke', 'Nakasongola', 'Namayingo', 'Namisindwa', 'Namutumba', 'Napak', 'Nebbi', 'Ngora', 'Ntoroko', 'Ntungamo', 'Nwoya', 'Obongi', 'Omoro', 'Otuke', 'Oyam', 'Pader', 'Pakwach', 'Pallisa', 'Rakai', 'Rubanda', 'Rubirizi', 'Rukiga', 'Rukungiri', 'Rwampara', 'Sembabule', 'Serere', 'Sheema', 'Sironko', 'Soroti', 'Tororo', 'Wakiso', 'Yumbe', 'Zombo'],
        'Ukraine' => ['Avtonomna Respublika Krym', 'Cherkaska oblast', 'Chernihivska oblast', 'Chernivetska oblast', 'Dnipropetrovska oblast', 'Donetska oblast', 'Ivano-Frankivska oblast', 'Kharkivska oblast', 'Khersonska oblast', 'Khmelnytska oblast', 'Kirovohradska oblast', 'Kyiv', 'Kyivska oblast', 'Luhanska oblast', 'Lvivska oblast', 'Mykolaivska oblast', 'Odeska oblast', 'Poltavska oblast', 'Rivnenska oblast', 'Sevastopol', 'Sumska oblast', 'Ternopilska oblast', 'Vinnytska oblast', 'Volynska oblast', 'Zakarpatska oblast', 'Zaporizka oblast', 'Zhytomyrska oblast'],
        'United Arab Emirates' => ['Abu Dhabi', 'Ajman', 'Dubai', 'Fujairah', 'Ras Al Khaimah', 'Sharjah', 'Umm Al Quwain'],
        'United Kingdom' => ['Aberdeen City', 'Aberdeenshire', 'Angus', 'Antrim and Newtownabbey', 'Ards and North Down', 'Argyll and Bute', 'Armagh City, Banbridge and Craigavon', 'Barking and Dagenham', 'Barnet', 'Barnsley', 'Bath and North East Somerset', 'Bedford', 'Belfast City', 'Bexley', 'Birmingham', 'Blackburn with Darwen', 'Blackpool', 'Blaenau Gwent', 'Bolton', 'Bournemouth, Christchurch and Poole', 'Bracknell Forest', 'Bradford', 'Brent', 'Bridgend', 'Brighton and Hove', 'Bristol', 'Bromley', 'Buckinghamshire', 'Bury', 'Caerphilly', 'Calderdale', 'Cambridgeshire', 'Camden', 'Cardiff', 'Carmarthenshire', 'Causeway Coast and Glens', 'Central Bedfordshire', 'Ceredigion', 'Cheshire East', 'Cheshire West and Chester', 'Clackmannanshire', 'Conwy', 'Cornwall', 'Coventry', 'Croydon', 'Cumbria', 'Darlington', 'Denbighshire', 'Derby', 'Derbyshire', 'Derry and Strabane', 'Devon', 'Doncaster', 'Dorset', 'Dudley', 'Dumfries and Galloway', 'Dundee City', 'Durham, County', 'Ealing', 'East Ayrshire', 'East Dunbartonshire', 'East Lothian', 'East Renfrewshire', 'East Riding of Yorkshire', 'East Sussex', 'Edinburgh', 'Eilean Siar', 'Enfield', 'Essex', 'Falkirk', 'Fermanagh and Omagh', 'Fife', 'Flintshire', 'Gateshead', 'Glasgow City', 'Gloucestershire', 'Greenwich', 'Gwynedd', 'Hackney', 'Halton', 'Hammersmith and Fulham', 'Hampshire', 'Haringey', 'Harrow', 'Hartlepool', 'Havering', 'Herefordshire', 'Hertfordshire', 'Highland', 'Hillingdon', 'Hounslow', 'Inverclyde', 'Isle of Anglesey', 'Isle of Wight', 'Isles of Scilly', 'Islington', 'Kensington and Chelsea', 'Kent', 'Kingston upon Hull', 'Kingston upon Thames', 'Kirklees', 'Knowsley', 'Lambeth', 'Lancashire', 'Leeds', 'Leicester', 'Leicestershire', 'Lewisham', 'Lincolnshire', 'Lisburn and Castlereagh', 'Liverpool', 'London', 'Luton', 'Manchester', 'Medway', 'Merthyr Tydfil', 'Merton', 'Mid and East Antrim', 'Mid-Ulster', 'Middlesbrough', 'Midlothian', 'Milton Keynes', 'Monmouthshire', 'Moray', 'Neath Port Talbot', 'Newcastle upon Tyne', 'Newham', 'Newport', 'Newry, Mourne and Down', 'Norfolk', 'North Ayrshire', 'North East Lincolnshire', 'North Lanarkshire', 'North Lincolnshire', 'North Northamptonshire', 'North Somerset', 'North Tyneside', 'North Yorkshire', 'Northumberland', 'Nottingham', 'Nottinghamshire', 'Oldham', 'Orkney Islands', 'Oxfordshire', 'Pembrokeshire', 'Perth and Kinross', 'Peterborough', 'Plymouth', 'Portsmouth', 'Powys', 'Reading', 'Redbridge', 'Redcar and Cleveland', 'Renfrewshire', 'Rhondda Cynon Taff', 'Richmond upon Thames', 'Rochdale', 'Rotherham', 'Rutland', 'Salford', 'Sandwell', 'Scottish Borders', 'Sefton', 'Sheffield', 'Shetland Islands', 'Shropshire', 'Slough', 'Solihull', 'Somerset', 'South Ayrshire', 'South Gloucestershire', 'South Lanarkshire', 'South Tyneside', 'Southampton', 'Southend-on-Sea', 'Southwark', 'St. Helens', 'Staffordshire', 'Stirling', 'Stockport', 'Stockton-on-Tees', 'Stoke-on-Trent', 'Suffolk', 'Sunderland', 'Surrey', 'Sutton', 'Swansea', 'Swindon', 'Tameside', 'Telford and Wrekin', 'The Vale of Glamorgan', 'Thurrock', 'Torbay', 'Torfaen', 'Tower Hamlets', 'Trafford', 'Wakefield', 'Walsall', 'Waltham Forest', 'Wandsworth', 'Warrington', 'Warwickshire', 'West Berkshire', 'West Dunbartonshire', 'West Lothian', 'West Northamptonshire', 'West Sussex', 'Westminster', 'Wigan', 'Wiltshire', 'Windsor and Maidenhead', 'Wirral', 'Wokingham', 'Wolverhampton', 'Worcestershire', 'Wrexham', 'York'],
        'United States' => ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'District of Columbia', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming'],
        'Uruguay' => ['Artigas', 'Canelones', 'Cerro Largo', 'Colonia', 'Durazno', 'Flores', 'Florida', 'Lavalleja', 'Maldonado', 'Montevideo', 'Paysandú', 'Rivera', 'Rocha', 'Río Negro', 'Salto', 'San José', 'Soriano', 'Tacuarembó', 'Treinta y Tres'],
        'Uzbekistan' => ['Andijon', 'Buxoro', 'Farg‘ona', 'Jizzax', 'Namangan', 'Navoiy', 'Qashqadaryo', 'Qoraqalpog‘iston Respublikasi', 'Samarqand', 'Sirdaryo', 'Surxondaryo', 'Toshkent', 'Xorazm'],
        'Vanuatu' => ['Malampa', 'Pénama', 'Sanma', 'Shéfa', 'Taféa', 'Torba'],
        'Vatican City' => ['Vatican City'],
        'Venezuela' => ['Amazonas', 'Anzoátegui', 'Apure', 'Aragua', 'Barinas', 'Bolívar', 'Carabobo', 'Cojedes', 'Delta Amacuro', 'Dependencias Federales', 'Distrito Capital', 'Falcón', 'Guárico', 'La Guaira', 'Lara', 'Miranda', 'Monagas', 'Mérida', 'Nueva Esparta', 'Portuguesa', 'Sucre', 'Trujillo', 'Táchira', 'Yaracuy', 'Zulia'],
        'Vietnam' => ['An Giang', 'Bà Rịa - Vũng Tàu', 'Bình Dương', 'Bình Phước', 'Bình Thuận', 'Bình Định', 'Bạc Liêu', 'Bắc Giang', 'Bắc Kạn', 'Bắc Ninh', 'Bến Tre', 'Cao Bằng', 'Cà Mau', 'Cần Thơ', 'Gia Lai', 'Hà Giang', 'Hà Nam', 'Hà Nội', 'Hà Tĩnh', 'Hòa Bình', 'Hưng Yên', 'Hải Dương', 'Hải Phòng', 'Hậu Giang', 'Hồ Chí Minh', 'Khánh Hòa', 'Kiến Giang', 'Kon Tum', 'Lai Châu', 'Long An', 'Lào Cai', 'Lâm Đồng', 'Lạng Sơn', 'Nam Định', 'Nghệ An', 'Ninh Bình', 'Ninh Thuận', 'Phú Thọ', 'Phú Yên', 'Quảng Bình', 'Quảng Nam', 'Quảng Ngãi', 'Quảng Ninh', 'Quảng Trị', 'Sóc Trăng', 'Sơn La', 'Thanh Hóa', 'Thái Bình', 'Thái Nguyên', 'Thừa Thiên-Huế', 'Tiền Giang', 'Trà Vinh', 'Tuyên Quang', 'Tây Ninh', 'Vĩnh Long', 'Vĩnh Phúc', 'Yên Bái', 'Điện Biên', 'Đà Nẵng', 'Đắk Lắk', 'Đắk Nông', 'Đồng Nai', 'Đồng Tháp'],
        'Yemen' => ['Abyan', 'Al Bayḑā’', 'Al Jawf', 'Al Mahrah', 'Al Maḩwīt', 'Al Ḩudaydah', 'Amānat al ‘Āşimah', 'Arkhabīl Suquţrá', 'Aḑ Ḑāli‘', 'Dhamār', 'Ibb', 'Laḩij', 'Ma’rib', 'Raymah', 'Shabwah', 'Tāʻizz', 'Şanʻā’', 'Şāʻdah', 'Ḩajjah', 'Ḩaḑramawt', '‘Adan', '‘Amrān'],
        'Zambia' => ['Central', 'Copperbelt', 'Eastern', 'Luapula', 'Lusaka', 'Muchinga', 'North-Western', 'Northern', 'Southern', 'Western'],
        'Zimbabwe' => ['Bulawayo', 'Harare', 'Manicaland', 'Mashonaland Central', 'Mashonaland East', 'Mashonaland West', 'Masvingo', 'Matabeleland North', 'Matabeleland South', 'Midlands'],
    ];

    /**
     * Standard Administrative Unit label per country (e.g. County for Kenya, State for US, District for Tanzania) (#132, #133, #134).
     */
    public const PLACE_UNIT_LABELS = [
        'Afghanistan' => 'Province',
        'Albania' => 'County',
        'Algeria' => 'Province',
        'Andorra' => 'Parish',
        'Angola' => 'Province',
        'Antigua and Barbuda' => 'Parish',
        'Argentina' => 'Province',
        'Armenia' => 'Region',
        'Australia' => 'State / Territory',
        'Austria' => 'State',
        'Azerbaijan' => 'Municipality',
        'Bahamas' => 'District',
        'Bahrain' => 'Governorate',
        'Bangladesh' => 'District',
        'Barbados' => 'Parish',
        'Belarus' => 'Region',
        'Belgium' => 'Province',
        'Belize' => 'District',
        'Benin' => 'Department',
        'Bhutan' => 'District',
        'Bolivia' => 'Department',
        'Bosnia and Herzegovina' => 'District',
        'Botswana' => 'District',
        'Brazil' => 'District',
        'Brunei' => 'District',
        'Bulgaria' => 'District',
        'Burkina Faso' => 'Province',
        'Burundi' => 'Province',
        'Cabo Verde' => 'Municipality',
        'Cambodia' => 'Province',
        'Cameroon' => 'Region',
        'Canada' => 'Province / Territory',
        'Central African Republic' => 'Prefecture',
        'Chad' => 'Province',
        'Chile' => 'Region',
        'China' => 'Province',
        'Colombia' => 'District',
        'Comoros' => 'State',
        'Congo (Brazzaville)' => 'Department',
        'Congo (Democratic Republic / DRC)' => 'Province',
        'Costa Rica' => 'Province',
        'Croatia' => 'County',
        'Cuba' => 'Province',
        'Cyprus' => 'District',
        'Czechia (Czech Republic)' => 'Region',
        'Côte d\'Ivoire' => 'District',
        'Denmark' => 'Region',
        'Djibouti' => 'Region',
        'Dominica' => 'Parish',
        'Dominican Republic' => 'Province',
        'Ecuador' => 'Province',
        'Egypt' => 'Governorate',
        'El Salvador' => 'Department',
        'Equatorial Guinea' => 'Province',
        'Eritrea' => 'Region',
        'Estonia' => 'County',
        'Eswatini' => 'Region',
        'Ethiopia' => 'State',
        'Fiji' => 'Province',
        'Finland' => 'Region',
        'France' => 'Region',
        'Gabon' => 'Province',
        'Gambia' => 'Division',
        'Georgia' => 'Region',
        'Germany' => 'State',
        'Ghana' => 'Region',
        'Greece' => 'Region',
        'Grenada' => 'Parish',
        'Guatemala' => 'Department',
        'Guinea' => 'Prefecture',
        'Guinea-Bissau' => 'Region',
        'Guyana' => 'Region',
        'Haiti' => 'Department',
        'Honduras' => 'Department',
        'Hungary' => 'County',
        'Iceland' => 'Region',
        'India' => 'State / Union Territory',
        'Indonesia' => 'Province',
        'Iran' => 'Province',
        'Iraq' => 'Governorate',
        'Ireland' => 'County',
        'Israel' => 'District',
        'Italy' => 'Province',
        'Jamaica' => 'Parish',
        'Japan' => 'Prefecture',
        'Jordan' => 'Governorate',
        'Kazakhstan' => 'Region',
        'Kenya' => 'County',
        'Kiribati' => 'State',
        'Korea (North)' => 'Province',
        'Korea (South)' => 'Province',
        'Kosovo' => 'District',
        'Kuwait' => 'Governorate',
        'Kyrgyzstan' => 'Region',
        'Laos' => 'Province',
        'Latvia' => 'State',
        'Lebanon' => 'Governorate',
        'Lesotho' => 'District',
        'Liberia' => 'County',
        'Libya' => 'Region',
        'Liechtenstein' => 'Municipality',
        'Lithuania' => 'County',
        'Luxembourg' => 'Canton',
        'Madagascar' => 'Province',
        'Malawi' => 'District',
        'Malaysia' => 'State',
        'Maldives' => 'Division',
        'Mali' => 'District',
        'Malta' => 'Municipality',
        'Marshall Islands' => 'Municipality',
        'Mauritania' => 'Region',
        'Mauritius' => 'District',
        'Mexico' => 'State',
        'Micronesia' => 'State',
        'Moldova' => 'District',
        'Monaco' => 'Division',
        'Mongolia' => 'Province',
        'Montenegro' => 'Municipality',
        'Morocco' => 'Region',
        'Mozambique' => 'Province',
        'Myanmar' => 'State',
        'Namibia' => 'Region',
        'Nauru' => 'District',
        'Nepal' => 'Province',
        'Netherlands' => 'Province',
        'New Zealand' => 'State',
        'Nicaragua' => 'Region',
        'Niger' => 'Region',
        'Nigeria' => 'State',
        'North Macedonia' => 'Municipality',
        'Norway' => 'County',
        'Oman' => 'Governorate',
        'Pakistan' => 'Province',
        'Palau' => 'State',
        'Palestine' => 'Governorate',
        'Panama' => 'Province',
        'Papua New Guinea' => 'District',
        'Paraguay' => 'Department',
        'Peru' => 'Region',
        'Philippines' => 'Province',
        'Poland' => 'Region',
        'Portugal' => 'District',
        'Qatar' => 'Municipality',
        'Romania' => 'Department',
        'Russia' => 'District',
        'Rwanda' => 'District',
        'Saint Kitts and Nevis' => 'Parish',
        'Saint Lucia' => 'District',
        'Saint Vincent and the Grenadines' => 'Parish',
        'Samoa' => 'District',
        'San Marino' => 'Municipality',
        'Sao Tome and Principe' => 'District',
        'Saudi Arabia' => 'Region',
        'Senegal' => 'Region',
        'Serbia' => 'District',
        'Seychelles' => 'District',
        'Sierra Leone' => 'Province',
        'Singapore' => 'District',
        'Slovakia' => 'Region',
        'Slovenia' => 'Municipality',
        'Solomon Islands' => 'Province',
        'Somalia' => 'Region',
        'South Africa' => 'Province',
        'South Sudan' => 'State',
        'Spain' => 'Province',
        'Sri Lanka' => 'District',
        'Sudan' => 'State',
        'Suriname' => 'District',
        'Sweden' => 'County',
        'Switzerland' => 'Canton',
        'Syria' => 'Province',
        'Taiwan' => 'County',
        'Tajikistan' => 'District',
        'Tanzania' => 'District',
        'Thailand' => 'Province',
        'Timor-Leste' => 'Region',
        'Togo' => 'Region',
        'Tonga' => 'Division',
        'Trinidad and Tobago' => 'Region',
        'Tunisia' => 'Governorate',
        'Turkey' => 'Province',
        'Turkmenistan' => 'Region',
        'Tuvalu' => 'State',
        'Uganda' => 'District',
        'Ukraine' => 'Region',
        'United Arab Emirates' => 'Emirate',
        'United Kingdom' => 'County / Council',
        'United States' => 'State',
        'Uruguay' => 'Department',
        'Uzbekistan' => 'Region',
        'Vanuatu' => 'Province',
        'Vatican City' => 'City State',
        'Venezuela' => 'District',
        'Vietnam' => 'Province',
        'Yemen' => 'Governorate',
        'Zambia' => 'Province',
        'Zimbabwe' => 'Province',
    ];

    public static function getPlaceHierarchy(): array
    {
        return self::PLACE_HIERARCHY;
    }

    public static function getPlaceUnitLabels(): array
    {
        return self::PLACE_UNIT_LABELS;
    }

    public static function getUnitLabelForCountry(string $country): string
    {
        return self::PLACE_UNIT_LABELS[$country] ?? 'County / State / District';
    }

    /**
     * Resolve Level 1 Country and Level 2 City/County/State from POST payload, supporting custom "Other" values.
     *
     * @return array{country: string, city_county: string}
     */
    public static function resolvePlaceFromPost(array $post, string $defaultCountry = 'Kenya', string $defaultCity = 'Nairobi'): array
    {
        $countrySel = trim((string)($post['country'] ?? ''));
        $countryCustom = trim((string)($post['country_custom'] ?? ''));
        $country = ($countrySel === '__custom__') ? $countryCustom : $countrySel;
        if ($country === '') {
            $country = $defaultCountry;
        }

        $citySel = trim((string)($post['city_county'] ?? ''));
        $cityCustom = trim((string)($post['city_county_custom'] ?? ''));
        $city = ($citySel === '__custom__') ? $cityCustom : $citySel;
        if ($city === '') {
            $city = $defaultCity;
        }

        return [
            'country' => mb_substr($country, 0, 80),
            'city_county' => mb_substr($city, 0, 100),
        ];
    }

    /**
     * Exact Spherical Haversine distance in kilometers.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2) ** 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
        return round(self::EARTH_RADIUS_KM * $c, 2);
    }

    /**
     * Bounding-box pre-filter coordinates for indexed SQL queries before exact Haversine sorting.
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / 111.045;
        $cosLat = max(0.01, abs(cos(deg2rad($lat))));
        $lngDelta = $radiusKm / (111.045 * $cosLat);

        return [
            'minLat' => round($lat - $latDelta, 7),
            'maxLat' => round($lat + $latDelta, 7),
            'minLng' => round($lng - $lngDelta, 7),
            'maxLng' => round($lng + $lngDelta, 7),
        ];
    }

    /**
     * Parse coordinates from a Maps URL (Google Maps, Apple Maps, Waze, OpenStreetMap, Plus.Codes),
     * DMS string, Open Location Code (Plus Code), or raw "lat, lng" string without network I/O.
     */
    public static function parseCoordinatesFromInput(string $input, ?string $fallbackLocality = null): ?array
    {
        $raw = trim($input);
        if ($raw === '') {
            return null;
        }

        $urlDecoded = rawurldecode($raw);

        // Pattern 1: !3d-1.2647!4d36.8021 (exact entity/destination pin in Google Maps URLs — take last valid match
        // so destination pin wins over zoomed-out @viewport or multi-stop origin)
        if (preg_match_all('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $urlDecoded, $mAll, PREG_SET_ORDER)) {
            for ($i = count($mAll) - 1; $i >= 0; $i--) {
                $valid = self::validateCoords((float)$mAll[$i][1], (float)$mAll[$i][2]);
                if ($valid !== null) {
                    return $valid;
                }
            }
        }

        // Pattern 2: !2d36.8021!3d-1.2647 (Google Maps pb= embed URLs where !2d = lng, !3d = lat without !4d)
        if (preg_match_all('/!2d(-?\d+\.\d+)!3d(-?\d+\.\d+)(?!\!4d)/', $urlDecoded, $mEmbed, PREG_SET_ORDER)) {
            for ($i = count($mEmbed) - 1; $i >= 0; $i--) {
                $valid = self::validateCoords((float)$mEmbed[$i][2], (float)$mEmbed[$i][1]);
                if ($valid !== null) {
                    return $valid;
                }
            }
        }

        // Pattern 3: OpenStreetMap mlat/mlon query params or #map=zoom/lat/lng hash
        if (preg_match('/[?&]mlat=(-?\d+\.\d+)[^#]*?[?&]mlon=(-?\d+\.\d+)/i', $urlDecoded, $mOsm) ||
            preg_match('/#map=\d+(?:\.\d+)?\/(-?\d+\.\d+)\/(-?\d+\.\d+)/i', $urlDecoded, $mOsm)) {
            $valid = self::validateCoords((float)$mOsm[1], (float)$mOsm[2]);
            if ($valid !== null) {
                return $valid;
            }
        }
        if (preg_match('/[?&]mlon=(-?\d+\.\d+)[^#]*?[?&]mlat=(-?\d+\.\d+)/i', $urlDecoded, $mOsmRev)) {
            $valid = self::validateCoords((float)$mOsmRev[2], (float)$mOsmRev[1]);
            if ($valid !== null) {
                return $valid;
            }
        }

        // Pattern 4: Explicit coordinate query/path params (?q=, ?ll=, ?sll=, ?query=, ?destination=, ?daddr=, ?coordinate=, ?viewpoint=, Waze to=ll., /maps/search/lat,lng)
        $querySpaced = str_replace('+', ' ', $urlDecoded);
        if (preg_match('/[?&](?:q|ll|sll|query|destination|daddr|coordinate|viewpoint)=(?:loc:)?\s*\+?(-?\d+\.\d+)\s*,\s*\+?(-?\d+\.\d+)/i', $urlDecoded, $mQp) ||
            preg_match('/[?&](?:q|ll|sll|query|destination|daddr|coordinate|viewpoint)=(?:loc:)?\s*(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/i', $querySpaced, $mQp) ||
            preg_match('/[?&]to=ll\.(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/i', $urlDecoded, $mQp) ||
            preg_match('#/maps/(?:search|place|dir/[^/]*)/\s*\+?(-?\d+\.\d+)\s*,\s*\+?(-?\d+\.\d+)#i', $urlDecoded, $mQp)) {
            $valid = self::validateCoords((float)$mQp[1], (float)$mQp[2]);
            if ($valid !== null) {
                return $valid;
            }
        }

        // Pattern 5: Degrees-Minutes-Seconds (DMS) pin coordinates, e.g. 1°15'52.9"S 36°48'07.6"E
        if (preg_match('/(\d{1,2})\s*°\s*(\d{1,2})\s*[\x27′]\s*(\d{1,2}(?:\.\d+)?)\s*(?:["″]|\x27\x27)?\s*([NS])[\s,+]+(\d{1,3})\s*°\s*(\d{1,2})\s*[\x27′]\s*(\d{1,2}(?:\.\d+)?)\s*(?:["″]|\x27\x27)?\s*([EW])/iu', $querySpaced, $mDms)) {
            $lat = ((int)$mDms[1]) + (((int)$mDms[2]) / 60.0) + (((float)$mDms[3]) / 3600.0);
            if (strtoupper($mDms[4]) === 'S') {
                $lat = -$lat;
            }
            $lng = ((int)$mDms[5]) + (((int)$mDms[6]) / 60.0) + (((float)$mDms[7]) / 3600.0);
            if (strtoupper($mDms[8]) === 'W') {
                $lng = -$lng;
            }
            $valid = self::validateCoords($lat, $lng);
            if ($valid !== null) {
                return $valid;
            }
        }

        // Pattern 6: @-1.2647,36.8021 or center=-1.2647,36.8021
        if (preg_match('/(?:@|[?&]center=)(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/i', $urlDecoded, $mAt)) {
            $valid = self::validateCoords((float)$mAt[1], (float)$mAt[2]);
            if ($valid !== null) {
                return $valid;
            }
        }

        // Pattern 7: Raw "lat, lng"
        if (preg_match('/^\s*\+?(-?\d+\.\d+)\s*,\s*\+?(-?\d+\.\d+)\s*$/', $raw, $mRaw)) {
            $valid = self::validateCoords((float)$mRaw[1], (float)$mRaw[2]);
            if ($valid !== null) {
                return $valid;
            }
        }

        // Pattern 8: Open Location Code / Plus Code (e.g. "6GCRPPP3+9M", "PPP3+9M Nairobi", or "https://plus.codes/6GCRJWFH+86")
        $isHttpUrl = (bool)preg_match('#^https?://#i', $raw);
        $isPlusCodesDomain = $isHttpUrl && (bool)preg_match('#^https?://(?:www\.)?plus\.codes/#i', $raw);
        return self::decodePlusCode($urlDecoded, $fallbackLocality, $isHttpUrl && !$isPlusCodesDomain);
    }

    /**
     * Check whether a URL points to a supported Maps / pin service.
     */
    public static function isMapsUrl(string $url): bool
    {
        $u = trim($url);
        if ($u === '') {
            return false;
        }
        return self::extractMapsUrlFromText($u) !== null && !preg_match('/\s/', $u);
    }

    /**
     * Extract the first supported Maps share/pin URL from free-form text (or a URL string).
     */
    public static function extractMapsUrlFromText(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }
        $pattern = '#https?://(?:'
            . 'maps\.app\.goo\.gl/[^\s"\'<>]+'
            . '|goo\.gl/maps/[^\s"\'<>]+'
            . '|g\.co/(?:kgs|maps)/[^\s"\'<>]+'
            . '|(?:www\.|maps\.)?google\.(?:com|co\.[a-z]{2}|com\.[a-z]{2}|[a-z]{2,3})/maps[^\s"\'<>]*'
            . '|maps\.google\.(?:com|co\.[a-z]{2}|com\.[a-z]{2}|[a-z]{2,3})/\?[^\s"\'<>]+'
            . '|maps\.apple\.com/[^\s"\'<>]+'
            . '|(?:www\.|ul\.)?waze\.com/(?:ul|live-map)[^\s"\'<>]*'
            . '|(?:www\.)?openstreetmap\.org/[^\s"\'<>]+'
            . '|osm\.org/[^\s"\'<>]+'
            . '|(?:www\.)?plus\.codes/[^\s"\'<>]+'
            . ')#i';

        if (!preg_match($pattern, $text, $m)) {
            return null;
        }
        return rtrim($m[0], ".,;)]}>\"'");
    }

    /**
     * Strict domain allowlist for outbound redirect resolution of Maps short/place URLs.
     */
    public static function isAllowedMapRedirectHost(string $host): bool
    {
        $h = strtolower(trim($host, '.[]'));
        if ($h === '') {
            return false;
        }
        $exactHosts = [
            'maps.app.goo.gl',
            'goo.gl',
            'g.co',
            'plus.codes',
            'www.plus.codes',
            'maps.apple.com',
            'waze.com',
            'www.waze.com',
            'ul.waze.com',
            'openstreetmap.org',
            'www.openstreetmap.org',
            'osm.org',
        ];
        if (in_array($h, $exactHosts, true)) {
            return true;
        }
        return (bool)preg_match('/^(?:www\.|maps\.)?google\.(?:com|co\.[a-z]{2}|com\.[a-z]{2}|[a-z]{2,3})$/', $h);
    }

    /**
     * Resolve coordinates from inline coordinates/Plus Codes OR by safely unrolling short/place Maps URLs
     * (e.g. maps.app.goo.gl, goo.gl/maps, g.co/kgs, google.com/maps/place/..., ?q=...&ftid=...).
     *
     * @param callable|null $httpFetcher Optional test seam `(string $url): array{status: int, headers: array<string,string>, body: string}`
     */
    public static function resolveCoordinatesFromUrlOrInput(
        string $input,
        bool $allowExternal = true,
        ?string $fallbackLocality = null,
        ?callable $httpFetcher = null,
        ?bool &$networkError = null
    ): ?array {
        $networkError = false;
        $raw = trim($input);
        if ($raw === '') {
            return null;
        }

        // 1. Fast synchronous parse when coordinates or Plus Code are directly present in the string
        $inline = self::parseCoordinatesFromInput($raw, $fallbackLocality);
        if ($inline !== null) {
            return [
                'latitude' => $inline['latitude'],
                'longitude' => $inline['longitude'],
                'source' => 'inline_coordinates',
            ];
        }

        // 2. Check if the input contains a supported Maps URL requiring redirect / place resolution
        $mapsUrl = self::extractMapsUrlFromText($raw);
        if ($mapsUrl === null || !$allowExternal) {
            return null;
        }

        try {
            $hopResult = self::fetchMapUrlSafe($mapsUrl, 5, $httpFetcher);
        } catch (\InvalidArgumentException $secEx) {
            // Domain allowlist or SSRF validation rejection — log and fail closed without marking transient error
            error_log('[LocationEngine] Blocked unsafe map URL (' . $mapsUrl . '): ' . $secEx->getMessage());
            return null;
        } catch (\RuntimeException $netEx) {
            $networkError = true;
            error_log('[LocationEngine] Transient error resolving map URL (' . $mapsUrl . '): ' . $netEx->getMessage());
            return null;
        }

        // Check if any intermediate redirect or final URL resolved directly to coordinates
        if ($hopResult['early_coords'] !== null) {
            return [
                'latitude' => $hopResult['early_coords']['latitude'],
                'longitude' => $hopResult['early_coords']['longitude'],
                'resolved_url' => $hopResult['final_url'],
                'source' => 'maps_redirect_url',
            ];
        }

        $finalUrl = $hopResult['final_url'];
        $fromFinalUrl = self::parseCoordinatesFromInput($finalUrl, $fallbackLocality);
        if ($fromFinalUrl !== null) {
            return [
                'latitude' => $fromFinalUrl['latitude'],
                'longitude' => $fromFinalUrl['longitude'],
                'resolved_url' => $finalUrl,
                'source' => 'maps_final_url',
            ];
        }

        // Check response body strictly for exact !3d!4d or !2d!3d pins (never generic IP-based <meta center=>)
        $body = $hopResult['body'];
        if ($body !== '') {
            if (preg_match_all('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $body, $mBody, PREG_SET_ORDER)) {
                for ($i = count($mBody) - 1; $i >= 0; $i--) {
                    $valid = self::validateCoords((float)$mBody[$i][1], (float)$mBody[$i][2]);
                    if ($valid !== null) {
                        return [
                            'latitude' => $valid['latitude'],
                            'longitude' => $valid['longitude'],
                            'resolved_url' => $finalUrl,
                            'source' => 'maps_html_pin',
                        ];
                    }
                }
            }
            $embedDirect = self::parseCoordinatesFromGoogleEmbedHtml($body);
            if ($embedDirect !== null) {
                return [
                    'latitude' => $embedDirect['latitude'],
                    'longitude' => $embedDirect['longitude'],
                    'resolved_url' => $finalUrl,
                    'source' => 'maps_embed_payload',
                ];
            }
        }

        // 3. When Google Maps redirects a mobile share link to a query/place URL without @ or !3d!4d
        // (e.g. https://maps.google.com?q=Ruaka+Shopping+Mall,...&ftid=0x...:0x...&entry=gps),
        // resolve the exact entity pin via Google Maps' lightweight output=embed endpoint.
        $embedTarget = self::buildGoogleMapsEmbedLookupUrl($finalUrl);
        if ($embedTarget !== null) {
            try {
                $embedRes = self::fetchMapUrlSafe($embedTarget['embed_url'], 4, $httpFetcher, false);
                $fromEmbed = self::parseCoordinatesFromGoogleEmbedHtml($embedRes['body'], $embedTarget['ftid']);
                if ($fromEmbed !== null) {
                    return [
                        'latitude' => $fromEmbed['latitude'],
                        'longitude' => $fromEmbed['longitude'],
                        'resolved_url' => $finalUrl,
                        'source' => 'maps_embed_entity',
                    ];
                }
            } catch (\InvalidArgumentException $secEx) {
                error_log('[LocationEngine] Blocked unsafe embed lookup URL: ' . $secEx->getMessage());
            } catch (\RuntimeException $netEx) {
                $networkError = true;
                error_log('[LocationEngine] Transient error fetching Google Maps embed lookup: ' . $netEx->getMessage());
            }
        }

        return null;
    }

    /**
     * Parse entity pin or camera target coordinates from a Google Maps `output=embed` HTML response (`initEmbed(...)`).
     */
    public static function parseCoordinatesFromGoogleEmbedHtml(string $html, ?string $ftid = null): ?array
    {
        if ($html === '' || !str_contains($html, 'initEmbed(')) {
            return null;
        }

        // 1. Exact ftid match (0xHEX1:0xHEX2 -> ["DEC1","DEC2"],"/g/...",null,[latE7,lngE7])
        if ($ftid !== null && preg_match('/^0x([0-9a-f]+):0x([0-9a-f]+)$/i', trim($ftid), $fm)) {
            $dec1 = self::hexUint64ToDecimal($fm[1]);
            $dec2 = self::hexUint64ToDecimal($fm[2]);
            $ftPattern = '/\["' . preg_quote($dec1, '/') . '"\s*,\s*"' . preg_quote($dec2, '/') . '"\]\s*,\s*"\/g\/[^"]*"\s*,\s*null\s*,\s*\[(\d{7,10})\s*,\s*(\d{7,10})\]/i';
            if (preg_match($ftPattern, $html, $em)) {
                $coords = self::decodeUnsignedE7Pair($em[1], $em[2]);
                if ($coords !== null) {
                    return $coords;
                }
            }
        }

        // 2. Direct spotlight float entity pin: "0x...:0x...","Place Name",[-1.3766863,36.9280114]
        if (preg_match('/"0x[0-9a-f]+:0x[0-9a-f]+"\s*,\s*"[^"]*"\s*,\s*\[(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)\]/i', $html, $mSpot)) {
            $coords = self::validateCoords((float)$mSpot[1], (float)$mSpot[2]);
            if ($coords !== null && (abs($coords['latitude']) > 0.0001 || abs($coords['longitude']) > 0.0001)) {
                return $coords;
            }
        }

        // 3. Top-ranked Knowledge Graph entity E7 coordinates: "/g/11yz9f17zj",null,[4282871070,367885910]
        if (preg_match('/"\/g\/[a-z0-9_]+"\s*,\s*null\s*,\s*\[(\d{7,10})\s*,\s*(\d{7,10})\]/i', $html, $mKg)) {
            $coords = self::decodeUnsignedE7Pair($mKg[1], $mKg[2]);
            if ($coords !== null) {
                return $coords;
            }
        }

        // 4. Embed camera target [[[distanceMeters, longitude, latitude],[0,0,0]]] when zoomed to an actual place (< 500 km)
        if (preg_match('/\[\[\[(\d+(?:\.\d+)?)\s*,\s*(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)\]\s*,\s*\[0\s*,\s*0\s*,\s*0\]/', $html, $mCam)) {
            $distMeters = (float)$mCam[1];
            $lng = (float)$mCam[2];
            $lat = (float)$mCam[3];
            if ($distMeters > 0.0 && $distMeters < 500000.0 && (abs($lat) > 0.0001 || abs($lng) > 0.0001)) {
                return self::validateCoords($lat, $lng);
            }
        }

        return null;
    }

    /**
     * Unified listing coordinate resolver used across public submission, Admin create/edit, Owner edit, and DB backfill.
     *
     * @return array{latitude: ?float, longitude: ?float, plus_code: ?string, google_maps_url: ?string, inferred: bool, network_error: bool}
     */
    public static function resolveCoordinatesFromListingData(
        array $data,
        bool $allowExternal = true,
        ?callable $httpFetcher = null,
        ?array $existingRow = null
    ): array {
        $rawLat = isset($data['latitude']) ? trim((string)$data['latitude']) : '';
        $rawLng = isset($data['longitude']) ? trim((string)$data['longitude']) : '';
        $lat = ($rawLat !== '' && is_numeric($rawLat)) ? (float)$rawLat : null;
        $lng = ($rawLng !== '' && is_numeric($rawLng)) ? (float)$rawLng : null;
        if ($lat !== null && $lng !== null) {
            $checked = self::validateCoords($lat, $lng);
            if ($checked === null) {
                $lat = null;
                $lng = null;
            } else {
                $lat = $checked['latitude'];
                $lng = $checked['longitude'];
            }
        } else {
            $lat = null;
            $lng = null;
        }

        $googleMapsUrl = trim((string)($data['google_maps_url'] ?? ''));
        $plusCode = trim((string)($data['plus_code'] ?? ''));
        $cityCounty = trim((string)($data['city_county'] ?? ''));

        // If a user pasted a Maps URL into the Plus Code box, move it cleanly to google_maps_url
        if (self::isMapsUrl($plusCode)) {
            if ($googleMapsUrl === '') {
                $googleMapsUrl = $plusCode;
            }
            $plusCode = '';
        }

        // If google_maps_url is blank, check whether a Maps link was posted inside landmark_directions, website_url, street_road, building_name, or description
        $freeTextMapsUrl = null;
        foreach (['landmark_directions', 'website_url', 'building_name', 'street_road', 'description'] as $textField) {
            $val = trim((string)($data[$textField] ?? ''));
            if ($val === '') {
                continue;
            }
            if ($textField === 'website_url' && !self::isMapsUrl($val)) {
                continue;
            }
            $extracted = self::extractMapsUrlFromText($val);
            if ($extracted !== null) {
                $freeTextMapsUrl = $extracted;
                break;
            }
        }
        if ($googleMapsUrl === '' && $freeTextMapsUrl !== null) {
            $googleMapsUrl = $freeTextMapsUrl;
        }

        // If editing an existing record where the user updated google_maps_url without manually changing old lat/lng, re-infer from the new link
        $mapsUrlChangedWithoutManualCoordEdit = false;
        if ($existingRow !== null && $lat !== null && $lng !== null && $googleMapsUrl !== '') {
            $oldMapsUrl = trim((string)($existingRow['google_maps_url'] ?? ''));
            $oldLat = isset($existingRow['latitude']) && $existingRow['latitude'] !== null && $existingRow['latitude'] !== '' ? round((float)$existingRow['latitude'], 6) : null;
            $oldLng = isset($existingRow['longitude']) && $existingRow['longitude'] !== null && $existingRow['longitude'] !== '' ? round((float)$existingRow['longitude'], 6) : null;
            if ($googleMapsUrl !== $oldMapsUrl && $oldLat !== null && $oldLng !== null && abs($lat - $oldLat) < 0.000001 && abs($lng - $oldLng) < 0.000001) {
                $mapsUrlChangedWithoutManualCoordEdit = true;
            }
        }

        $inferred = false;
        $hadNetworkError = false;

        if ($lat === null || $lng === null || $mapsUrlChangedWithoutManualCoordEdit) {
            $candidates = [];
            if ($googleMapsUrl !== '') {
                $candidates[] = $googleMapsUrl;
            }
            if ($plusCode !== '' && !$mapsUrlChangedWithoutManualCoordEdit) {
                $candidates[] = $plusCode;
            }
            if ($freeTextMapsUrl !== null && $freeTextMapsUrl !== $googleMapsUrl) {
                $candidates[] = $freeTextMapsUrl;
            }

            foreach ($candidates as $candidate) {
                $netErr = false;
                $resolved = self::resolveCoordinatesFromUrlOrInput(
                    $candidate,
                    $allowExternal,
                    $cityCounty !== '' ? $cityCounty : null,
                    $httpFetcher,
                    $netErr
                );
                if ($netErr) {
                    $hadNetworkError = true;
                }
                if ($resolved !== null) {
                    $lat = $resolved['latitude'];
                    $lng = $resolved['longitude'];
                    $inferred = true;
                    break;
                }
            }
        }

        if ($lat !== null && $lng !== null && ($plusCode === '' || $mapsUrlChangedWithoutManualCoordEdit)) {
            $plusCode = self::encodePlusCode($lat, $lng);
        }

        return [
            'latitude' => $lat,
            'longitude' => $lng,
            'plus_code' => $plusCode !== '' ? $plusCode : null,
            'google_maps_url' => $googleMapsUrl !== '' ? $googleMapsUrl : null,
            'inferred' => $inferred,
            'network_error' => $hadNetworkError,
        ];
    }

    /**
     * Follow up to $maxHops HTTP redirects across allowlisted map domains with SSRF IP pinning.
     *
     * @return array{final_url: string, body: string, early_coords: ?array}
     */
    private static function fetchMapUrlSafe(
        string $startUrl,
        int $maxHops = 5,
        ?callable $httpFetcher = null,
        bool $stopOnInlinePinRedirect = true
    ): array {
        $currentUrl = $startUrl;
        $maxBytes = 98304; // 96 KB cap

        for ($hop = 0; $hop <= $maxHops; $hop++) {
            $parts = parse_url($currentUrl);
            $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
            if (!self::isAllowedMapRedirectHost($host)) {
                throw new \InvalidArgumentException("Host '{$host}' is not an allowlisted map provider.");
            }

            if ($httpFetcher !== null) {
                // Still run scheme/port/private-IP literal checks when a test fetcher is supplied
                WebsiteEnricher::validateSafeUrlAndResolveIp($currentUrl, static fn(string $_h): array => ['142.250.180.14']);
                $resp = $httpFetcher($currentUrl);
                $status = (int)($resp['status'] ?? 200);
                $headers = (array)($resp['headers'] ?? []);
                $body = substr((string)($resp['body'] ?? ''), 0, $maxBytes);
                $location = null;
                foreach ($headers as $hk => $hv) {
                    if (strtolower((string)$hk) === 'location') {
                        $location = trim((string)$hv);
                        break;
                    }
                }
            } else {
                $validated = WebsiteEnricher::validateSafeUrlAndResolveIp($currentUrl);
                $currentUrl = $validated['url'];
                if (!function_exists('curl_init')) {
                    throw new \RuntimeException('cURL extension is not available to resolve short map URLs.');
                }
                $ch = curl_init($validated['url']);
                if ($ch === false) {
                    throw new \RuntimeException('Failed to initialize HTTP client for map URL resolution.');
                }
                $rawHeaders = [];
                $buffer = '';
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_HEADER => false,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BusherianBiasharas/1.0; +https://www.busherianbiasharas.com)',
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language: en-KE,en;q=0.9',
                    ],
                    CURLOPT_RESOLVE => [WebsiteEnricher::formatCurlResolveEntry($validated['host'], $validated['port'], $validated['ip'])],
                    CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$rawHeaders): int {
                        $trimmed = trim($headerLine);
                        if ($trimmed !== '' && str_contains($trimmed, ':')) {
                            [$k, $v] = explode(':', $trimmed, 2);
                            if (strtolower(trim($k)) === 'location') {
                                $rawHeaders['location'] = trim($v);
                            }
                        }
                        return strlen($headerLine);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, $maxBytes): int {
                        $rem = $maxBytes - strlen($buffer);
                        if ($rem > 0) {
                            $buffer .= substr($chunk, 0, $rem);
                        }
                        return strlen($buffer) >= $maxBytes ? 0 : strlen($chunk);
                    },
                ]);
                $ok = curl_exec($ch);
                $errno = curl_errno($ch);
                $errMsg = curl_error($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($ok === false && $errno !== CURLE_WRITE_ERROR && $status === 0) {
                    throw new \RuntimeException('HTTP request failed: ' . ($errMsg !== '' ? $errMsg : "cURL errno {$errno}"));
                }
                $location = $rawHeaders['location'] ?? null;
                $body = $buffer;
            }

            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null && $location !== '') {
                if (str_starts_with($location, '//')) {
                    $location = ($parts['scheme'] ?? 'https') . ':' . $location;
                } elseif (str_starts_with($location, '/')) {
                    $location = ($parts['scheme'] ?? 'https') . '://' . $host . $location;
                }

                // Fast-path: if redirect Location header already embeds an exact !3d!4d / !2d!3d / ?q=lat,lng pin, extract without extra network round-trips
                if ($stopOnInlinePinRedirect && (
                    str_contains($location, '!3d') ||
                    preg_match('/[?&](?:q|ll|query|destination)=(?:loc:)?\s*\+?-?\d+\.\d+\s*,\s*\+?-?\d+\.\d+/i', rawurldecode($location))
                )) {
                    $early = self::parseCoordinatesFromInput($location);
                    if ($early !== null) {
                        return [
                            'final_url' => $location,
                            'body' => '',
                            'early_coords' => $early,
                        ];
                    }
                }

                $currentUrl = $location;
                continue;
            }

            if ($status >= 500 || $status === 429 || $status === 0) {
                throw new \RuntimeException("Map provider returned HTTP {$status}.");
            }

            return [
                'final_url' => $currentUrl,
                'body' => $body,
                'early_coords' => null,
            ];
        }

        return [
            'final_url' => $currentUrl,
            'body' => '',
            'early_coords' => self::parseCoordinatesFromInput($currentUrl),
        ];
    }

    /**
     * Build a Google Maps `output=embed` lookup URL when a redirected Google Maps URL identifies a place/query without inline coordinates.
     *
     * @return ?array{embed_url: string, ftid: ?string}
     */
    private static function buildGoogleMapsEmbedLookupUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = strtolower((string)$parts['host']);
        if (!preg_match('/^(?:www\.|maps\.)?google\.(?:com|co\.[a-z]{2}|com\.[a-z]{2}|[a-z]{2,3})$/', $host)) {
            return null;
        }

        $qs = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $qs);
        }

        $q = trim((string)($qs['q'] ?? $qs['query'] ?? ''));
        $ftid = isset($qs['ftid']) ? trim((string)$qs['ftid']) : null;
        $cid = isset($qs['cid']) ? trim((string)$qs['cid']) : null;

        $path = (string)($parts['path'] ?? '');
        if ($q === '' && preg_match('#/maps/place/([^/@?]+)#i', $path, $pm)) {
            $q = trim(rawurldecode(str_replace('+', ' ', $pm[1])));
        }
        if ($ftid === null && preg_match('/!1s(0x[0-9a-f]+:0x[0-9a-f]+)/i', rawurldecode($url), $fm)) {
            $ftid = $fm[1];
        }

        if ($q === '' && $ftid === null && $cid === null) {
            return null;
        }

        $params = [];
        if ($q !== '') {
            $params['q'] = $q;
        }
        if ($ftid !== null && $ftid !== '') {
            $params['ftid'] = $ftid;
        }
        if ($cid !== null && $cid !== '') {
            $params['cid'] = $cid;
        }
        $params['output'] = 'embed';

        return [
            'embed_url' => 'https://www.google.com/maps?' . http_build_query($params),
            'ftid' => $ftid,
        ];
    }

    /**
     * Convert two unsigned 32-bit E7 integers from Google Maps `initEmbed` into validated decimal degrees.
     */
    private static function decodeUnsignedE7Pair(string $rawLatE7, string $rawLngE7): ?array
    {
        $uLat = (int)$rawLatE7;
        $uLng = (int)$rawLngE7;
        if ($uLat > 2147483647) {
            $uLat -= 4294967296;
        }
        if ($uLng > 2147483647) {
            $uLng -= 4294967296;
        }
        $lat = $uLat / 10000000.0;
        $lng = $uLng / 10000000.0;
        if (abs($lat) < 0.0001 && abs($lng) < 0.0001) {
            return null;
        }
        return self::validateCoords($lat, $lng);
    }

    /**
     * Pure-PHP base-16 to unsigned base-10 string converter for 64-bit Google Maps feature IDs (no GMP/BCMath dependency).
     */
    private static function hexUint64ToDecimal(string $hex): string
    {
        $clean = strtolower(ltrim(trim($hex), '0'));
        if ($clean === '') {
            return '0';
        }
        $dec = '0';
        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $digit = hexdec($clean[$i]);
            $carry = $digit;
            $out = '';
            for ($j = strlen($dec) - 1; $j >= 0; $j--) {
                $val = ((int)$dec[$j]) * 16 + $carry;
                $out = (string)($val % 10) . $out;
                $carry = intdiv($val, 10);
            }
            while ($carry > 0) {
                $out = (string)($carry % 10) . $out;
                $carry = intdiv($carry, 10);
            }
            $dec = $out;
        }
        return $dec;
    }

    /**
     * Decode full (8+2) or short (4+2 with city/area name) Open Location Code (Plus Code).
     */
    public static function decodePlusCode(string $input, ?string $fallbackLocality = null, bool $requireFullPrefix = false): ?array
    {
        $clean = strtoupper(trim($input));
        if (!str_contains($clean, '+')) {
            return null;
        }

        $prefixQuantifier = $requireFullPrefix ? '{8}' : '{4,8}';
        if (!preg_match('/(?:^|[\s,\/=])([23456789CFGHJMPQRVWX]' . $prefixQuantifier . '\+[23456789CFGHJMPQRVWX]{2,5})(?:[\s,]+([A-Z][A-Z\s\-]{1,30}))?(?=$|[\s,&#?])/', $clean, $m)) {
            return null;
        }

        $code = $m[1];
        $localityRaw = strtolower(trim($m[2] ?? ($fallbackLocality ?? 'nairobi')));
        [$prefix, $suffix] = explode('+', $code, 2);

        // If short code (4 or 6 chars before '+'), recover full 8-char prefix using reference city/area centroid
        if (strlen($prefix) < 8) {
            if (strlen($prefix) % 2 !== 0) {
                return null;
            }
            $ref = self::KENYA_CITY_CENTROIDS[$localityRaw] ?? null;
            if ($ref === null) {
                foreach (self::KENYA_CITY_CENTROIDS as $cKey => $cCoords) {
                    if (str_contains($localityRaw, $cKey)) {
                        $ref = $cCoords;
                        break;
                    }
                }
            }
            if ($ref === null) {
                foreach (self::KENYA_PLACE_INDEX as $pKey => $pInfo) {
                    if (str_contains($localityRaw, $pKey)) {
                        $ref = [$pInfo[0], $pInfo[1]];
                        break;
                    }
                }
            }
            $ref = $ref ?? self::KENYA_CITY_CENTROIDS['nairobi'];
            $fullRef = self::encodePlusCode($ref[0], $ref[1]);
            $fullPrefix = substr($fullRef, 0, 8 - strlen($prefix)) . $prefix;
            $prefix = $fullPrefix;
        }

        $full = $prefix . $suffix;
        $weights = [20.0, 1.0, 0.05, 0.0025, 0.000125];
        $lat = -90.0;
        $lng = -180.0;

        $pairs = min(5, intdiv(strlen($full), 2));
        for ($i = 0; $i < $pairs; $i++) {
            $latChar = $full[$i * 2];
            $lngChar = $full[$i * 2 + 1];
            $latIdx = strpos(self::OLC_ALPHABET, $latChar);
            $lngIdx = strpos(self::OLC_ALPHABET, $lngChar);
            if ($latIdx === false || $lngIdx === false) {
                return null;
            }
            $lat += $latIdx * $weights[$i];
            $lng += $lngIdx * $weights[$i];
        }

        $res = $weights[$pairs - 1];
        return self::validateCoords(round($lat + ($res / 2.0), 6), round($lng + ($res / 2.0), 6));
    }

    public static function encodePlusCode(float $lat, float $lng): string
    {
        $adjLat = max(0.0, min(179.999999, $lat + 90.0));
        $adjLng = max(0.0, min(359.999999, $lng + 180.0));
        $weights = [20.0, 1.0, 0.05, 0.0025, 0.000125];
        $out = '';
        for ($i = 0; $i < 5; $i++) {
            $w = $weights[$i];
            $latDigit = (int)floor($adjLat / $w) % 20;
            $lngDigit = (int)floor($adjLng / $w) % 20;
            $out .= self::OLC_ALPHABET[$latDigit] . self::OLC_ALPHABET[$lngDigit];
            $adjLat -= $latDigit * $w;
            $adjLng -= $lngDigit * $w;
            if ($i === 3) {
                $out .= '+';
            }
        }
        return $out;
    }

    private static function validateCoords(float $lat, float $lng): ?array
    {
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }
        return ['latitude' => round($lat, 6), 'longitude' => round($lng, 6)];
    }

    /**
     * Compute human-readable 8-point compass bearing from (lat1,lng1) to (lat2,lng2).
     */
    public static function compassBearing(float $lat1, float $lng1, float $lat2, float $lng2): string
    {
        $dLng = deg2rad($lng2 - $lng1);
        $y = sin($dLng) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2))
           - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLng);
        $brng = fmod((rad2deg(atan2($y, $x)) + 360.0), 360.0);
        $dirs = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'];
        return $dirs[(int)round($brng / 45.0) % 8];
    }

    /**
     * Calculate exact straight-line distance, driving road distance, estimated drive duration,
     * road polyline geometry, and turn-by-turn street directions (OSRM with automatic offline fallback).
     */
    public static function calculateRouteAndDirections(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        bool $allowExternalRouting = true
    ): array {
        $straightKm = self::haversineKm($fromLat, $fromLng, $toLat, $toLng);
        $bearing = self::compassBearing($fromLat, $fromLng, $toLat, $toLng);

        if ($allowExternalRouting && function_exists('curl_init')) {
            $osrmUrl = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%0.6F,%0.6F;%0.6F,%0.6F?overview=full&geometries=geojson&steps=true',
                $fromLng,
                $fromLat,
                $toLng,
                $toLat
            );
            $ch = curl_init($osrmUrl);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_USERAGENT => 'BusherianBiasharasDirectory/0.3 (+https://www.busherianbiasharas.com)',
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ]);
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (is_string($raw) && $code === 200) {
                    $json = json_decode($raw, true);
                    if (is_array($json) && ($json['code'] ?? '') === 'Ok' && !empty($json['routes'][0])) {
                        $route = $json['routes'][0];
                        $roadKm = round(((float)($route['distance'] ?? 0)) / 1000.0, 2);
                        $durationMin = max(1, (int)round(((float)($route['duration'] ?? 0)) / 60.0));

                        $latLngs = [];
                        foreach (($route['geometry']['coordinates'] ?? []) as $pt) {
                            if (is_array($pt) && count($pt) >= 2) {
                                $latLngs[] = [round((float)$pt[1], 6), round((float)$pt[0], 6)];
                            }
                        }

                        // Consolidate OSRM steps into natural Kenyan left/right road transitions (no compass bearings)
                        $segments = [];
                        $pendingMod = '';
                        $pendingType = '';

                        foreach (($route['legs'][0]['steps'] ?? []) as $st) {
                            $maneuver = (array)($st['maneuver'] ?? []);
                            $mType = strtolower(trim((string)($maneuver['type'] ?? '')));
                            $mMod = strtolower(trim((string)($maneuver['modifier'] ?? '')));
                            if ($mType === 'arrive') {
                                continue;
                            }
                            $rName = trim((string)($st['name'] ?? ''));
                            $dM = (float)($st['distance'] ?? 0.0);
                            $rLower = strtolower($rName);

                            if ($rName === '' || $rLower === 'unnamed road' || str_contains($rLower, 'roundabout')) {
                                if (($mMod !== '' && (str_contains($mMod, 'left') || str_contains($mMod, 'right'))) && $pendingMod === '') {
                                    $pendingMod = $mMod;
                                    $pendingType = $mType;
                                }
                                if (!empty($segments)) {
                                    $segments[count($segments) - 1]['distance'] += $dM;
                                }
                                continue;
                            }

                            $lastIdx = count($segments) - 1;
                            if ($lastIdx >= 0 && self::isSameRoadName($segments[$lastIdx]['name'], $rName)) {
                                $segments[$lastIdx]['distance'] += $dM;
                            } else {
                                $useType = $mType;
                                $useMod = $mMod;
                                if ($useMod === '' && $pendingMod !== '') {
                                    $useMod = $pendingMod;
                                    $useType = $pendingType !== '' ? $pendingType : $useType;
                                }
                                $segments[] = [
                                    'name' => $rName,
                                    'distance' => $dM,
                                    'type' => $useType,
                                    'modifier' => $useMod,
                                ];
                                $pendingMod = '';
                                $pendingType = '';
                            }
                        }

                        // Absorb intermediate micro-crossings (< 120m) so roundabout islands don't split a continuous road
                        $cleaned = [];
                        $segCount = count($segments);
                        foreach ($segments as $idx => $seg) {
                            $isFirst = ($idx === 0);
                            $isLast = ($idx === $segCount - 1);
                            if (!$isFirst && !$isLast && $seg['distance'] < 120.0 && !empty($cleaned)) {
                                $cleaned[count($cleaned) - 1]['distance'] += $seg['distance'];
                                continue;
                            }
                            $cLast = count($cleaned) - 1;
                            if ($cLast >= 0 && self::isSameRoadName($cleaned[$cLast]['name'], $seg['name'])) {
                                $cleaned[$cLast]['distance'] += $seg['distance'];
                            } else {
                                $cleaned[] = $seg;
                            }
                        }

                        $corridors = [];
                        $steps = [];
                        foreach ($cleaned as $i => $seg) {
                            $rName = $seg['name'];
                            $corridors[] = $rName;
                            $dM = (float)$seg['distance'];
                            $dLabel = $dM >= 1000.0
                                ? (round($dM / 1000.0, 1) . ' km')
                                : (max(10, (int)round($dM / 10.0) * 10) . ' m');
                            $t = $seg['type'];
                            $mod = $seg['modifier'];

                            if ($i === 0) {
                                if (str_contains($mod, 'left') && $t !== 'depart') {
                                    $instr = "Start by turning left onto {$rName}";
                                } elseif (str_contains($mod, 'right') && $t !== 'depart') {
                                    $instr = "Start by turning right onto {$rName}";
                                } else {
                                    $instr = "Start on {$rName} and keep straight";
                                }
                            } else {
                                if (in_array($t, ['roundabout', 'rotary', 'exit roundabout', 'exit rotary'], true)) {
                                    if (str_contains($mod, 'left')) {
                                        $instr = "At the roundabout, turn left onto {$rName}";
                                    } elseif (str_contains($mod, 'right')) {
                                        $instr = "At the roundabout, turn right onto {$rName}";
                                    } else {
                                        $instr = "At the roundabout, take the exit onto {$rName}";
                                    }
                                } elseif (str_contains($mod, 'left')) {
                                    $instr = "Turn left onto {$rName}";
                                } elseif (str_contains($mod, 'right')) {
                                    $instr = "Turn right onto {$rName}";
                                } elseif ($mod === 'uturn') {
                                    $instr = "Make a U-turn onto {$rName}";
                                } else {
                                    $instr = "Continue straight onto {$rName}";
                                }
                            }

                            $steps[] = [
                                'instruction' => $instr,
                                'distance' => $dLabel,
                            ];
                        }

                        if (empty($steps)) {
                            $turnWord = ($toLng >= $fromLng) ? 'Turn right' : 'Turn left';
                            $steps[] = [
                                'instruction' => "{$turnWord} onto the main road and continue toward destination",
                                'distance' => "{$roadKm} km (~{$durationMin} min)",
                            ];
                        }

                        if (!empty($latLngs)) {
                            return [
                                'mode' => 'osrm_driving',
                                'straight_km' => $straightKm,
                                'road_km' => $roadKm,
                                'duration_min' => $durationMin,
                                'corridors' => $corridors,
                                'polyline' => $latLngs,
                                'steps' => $steps,
                            ];
                        }
                    }
                }
            }
        }

        $estRoadKm = round($straightKm * 1.28, 2);
        $estDurationMin = max(2, (int)round(($estRoadKm / 30.0) * 60.0));
        $turnDir = ($toLng >= $fromLng) ? 'right' : 'left';
        return [
            'mode' => 'estimated_direct',
            'straight_km' => $straightKm,
            'road_km' => $estRoadKm,
            'duration_min' => $estDurationMin,
            'polyline' => [
                [round($fromLat, 6), round($fromLng, 6)],
                [round($toLat, 6), round($toLng, 6)],
            ],
            'corridors' => [],
            'steps' => [
                [
                    'instruction' => 'Start on your nearest main road and keep straight',
                    'distance' => '',
                ],
                [
                    'instruction' => "Turn {$turnDir} at the main junction toward destination",
                    'distance' => "~{$estRoadKm} km (~{$estDurationMin} min)",
                ],
            ],
        ];
    }

    private static function isSameRoadName(string $a, string $b): bool
    {
        $ca = strtolower(trim($a));
        $cb = strtolower(trim($b));
        if ($ca === $cb) {
            return true;
        }
        similar_text($ca, $cb, $pct);
        return $pct >= 88.0;
    }

    /**
     * Built-in Kenyan street, neighborhood, and town coordinates for instant/offline fallback.
     */
    public const KENYA_PLACE_INDEX = [
        'marist lane' => [-1.344470, 36.728656, 'Marist Lane, Karen, Nairobi'],
        'karen' => [-1.321000, 36.704200, 'Karen, Nairobi'],
        'langata' => [-1.348900, 36.747900, 'Langata, Nairobi'],
        'lavington' => [-1.278800, 36.771200, 'Lavington, Nairobi'],
        'kilimani' => [-1.292100, 36.787900, 'Kilimani, Nairobi'],
        'kileleshwa' => [-1.281400, 36.782800, 'Kileleshwa, Nairobi'],
        'westlands' => [-1.263500, 36.803200, 'Westlands, Nairobi'],
        'parklands' => [-1.261200, 36.817500, 'Parklands, Nairobi'],
        'gigiri' => [-1.236400, 36.812200, 'Gigiri, Nairobi'],
        'runda' => [-1.219500, 36.819000, 'Runda, Nairobi'],
        'muthaiga' => [-1.249800, 36.836500, 'Muthaiga, Nairobi'],
        'upper hill' => [-1.298600, 36.814700, 'Upper Hill, Nairobi'],
        'hurlingham' => [-1.293500, 36.796200, 'Hurlingham, Nairobi'],
        'ngong road' => [-1.300500, 36.768900, 'Ngong Road, Nairobi'],
        'south c' => [-1.319800, 36.828600, 'South C, Nairobi'],
        'south b' => [-1.310200, 36.840500, 'South B, Nairobi'],
        'industrial area' => [-1.308800, 36.855200, 'Industrial Area, Nairobi'],
        'mombasa road' => [-1.328500, 36.854200, 'Mombasa Road, Nairobi'],
        'jkia' => [-1.319167, 36.927500, 'JKIA Airport, Nairobi'],
        'syokimau' => [-1.359200, 36.935800, 'Syokimau, Machakos'],
        'athi river' => [-1.456300, 36.978200, 'Athi River, Machakos'],
        'lukenya' => [-1.482500, 37.051400, 'Lukenya, Machakos'],
        'kitengela' => [-1.474800, 36.960200, 'Kitengela, Kajiado'],
        'isinya' => [-1.683300, 36.850000, 'Isinya, Kajiado'],
        'ngong' => [-1.361800, 36.656600, 'Ngong, Kajiado'],
        'ongata rongai' => [-1.396400, 36.758200, 'Ongata Rongai, Kajiado'],
        'kikuyu' => [-1.246300, 36.662900, 'Kikuyu (Alliance / Bush), Kiambu'],
        'alliance' => [-1.246300, 36.662900, 'Alliance (Bush), Kikuyu'],
        'lower kabete' => [-1.240500, 36.762200, 'Lower Kabete, Nairobi'],
        'waiyaki way' => [-1.264700, 36.792100, 'Waiyaki Way, Nairobi'],
        'thika road' => [-1.232200, 36.878800, 'Thika Road, Nairobi'],
        'kasarani' => [-1.221000, 36.897500, 'Kasarani, Nairobi'],
        'roysambu' => [-1.218500, 36.887000, 'Roysambu, Nairobi'],
        'ruaka' => [-1.204500, 36.776500, 'Ruaka, Kiambu'],
        'kiambu' => [-1.171400, 36.835600, 'Kiambu Road / Town'],
        'tigoni' => [-1.135400, 36.674200, 'Tigoni, Limuru'],
        'limuru' => [-1.106900, 36.642900, 'Limuru, Kiambu'],
        'ruiru' => [-1.148600, 36.960500, 'Ruiru, Kiambu'],
        'juja' => [-1.102600, 37.013200, 'Juja, Kiambu'],
        'thika' => [-1.033300, 37.069300, 'Thika, Kiambu'],
        'cbd' => [-1.286389, 36.817223, 'Nairobi CBD'],
    ];

    /**
     * Geocode a Kenyan street, estate, landmark, Plus Code, or coordinate string into {latitude, longitude, label}.
     */
    public static function geocodeKenyanLocation(string $rawQuery, bool $allowExternal = true): ?array
    {
        $q = trim($rawQuery);
        if ($q === '') {
            return null;
        }

        // 1. Check if it is coordinates, a Maps URL (including short links like maps.app.goo.gl), or a Plus Code
        $resolvedPin = self::resolveCoordinatesFromUrlOrInput($q, $allowExternal);
        if ($resolvedPin !== null) {
            return [
                'latitude' => $resolvedPin['latitude'],
                'longitude' => $resolvedPin['longitude'],
                'plus_code' => self::encodePlusCode($resolvedPin['latitude'], $resolvedPin['longitude']),
                'label' => $q,
                'source' => $resolvedPin['source'] ?? 'coordinates_or_plus_code',
            ];
        }
        if (preg_match('#^https?://#i', $q)) {
            return null;
        }

        $lower = strtolower($q);

        // 2. Query OpenStreetMap Nominatim (bounded to Kenya) when external requests are enabled
        if ($allowExternal && function_exists('curl_init')) {
            $searchQuery = str_contains($lower, 'kenya') ? $q : ($q . ', Kenya');
            $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                'q' => $searchQuery,
                'format' => 'jsonv2',
                'countrycodes' => 'ke',
                'limit' => 1,
            ]);
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_USERAGENT => 'BusherianBiasharasDirectory/0.5 (+https://www.busherianbiasharas.com)',
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ]);
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (is_string($raw) && $code === 200) {
                    $json = json_decode($raw, true);
                    if (is_array($json) && !empty($json[0]['lat']) && !empty($json[0]['lon'])) {
                        $lat = round((float)$json[0]['lat'], 6);
                        $lng = round((float)$json[0]['lon'], 6);
                        $disp = (string)($json[0]['display_name'] ?? $q);
                        $parts = array_slice(array_map('trim', explode(',', $disp)), 0, 3);
                        return [
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'label' => implode(', ', $parts) ?: $q,
                            'source' => 'nominatim_ke',
                        ];
                    }
                }
            }
        }

        // 3. Fallback to built-in Kenyan place index (also ensures deterministic offline/CI tests)
        foreach (self::KENYA_PLACE_INDEX as $key => $info) {
            if (str_contains($lower, $key)) {
                return [
                    'latitude' => $info[0],
                    'longitude' => $info[1],
                    'label' => $info[2],
                    'source' => 'kenya_place_index',
                ];
            }
        }

        return null;
    }
}
