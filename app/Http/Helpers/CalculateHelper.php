<?php


namespace App\Http\Helpers;


use App\Http\Controllers\SaveToolController;
use App\Models\Risultato_singolaZO;
use MathPHP;

class CalculateHelper
{

    /**
     * Calcola Costo Investimento sommando l'importo di ogni ZO
     *
     * @input costo_medio_lampada               -> $has[lamp_cost]
     * @input costo_infrastruttura              ->    non chiaro, forse $has[infrastructure_maintenance_cost]
     * @input costo_medio_smaltimento_lampada   -> $has[lamp_disposal]
     * @input n_lampade                         -> $cluster[lamp_num]
     * @input costo_rifacimento_imp_elett       -> $has[system_renovation_cost] colonna da aggiungere
     * @input costo_attività_prodomiche         -> $has[prodromal_activities_cost]
     * @input costo_quadro                      -> $has[panel_cost]
     * @input n_quadri_el                       -> $has[panel_num]
     *
     * Utilizzato solo per le HAS TOBE
     * */
    public static function calcolaImportoInvestimentoPerHA($ha){
        $clusters = SaveToolController::getClustersByHaId($ha["id"])["clusters"];
        $sommaParziale = 0;
        if(count($clusters) > 0){
            for ($i = 0; $i < count($clusters); $i++) {
                $cluster = $clusters[$i];
                $sommaParziale += ($ha["lamp_cost"] + $ha["infrastructure_maintenance_cost"] + $ha["lamp_disposal"]) * $cluster["lamp_num"];
            }
            $sommaParziale += $ha["system_renovation_cost"] + $ha["prodromal_activities_cost"] + ($ha["panel_cost"] * $ha["panel_num"]);
        }
        return $sommaParziale;
    }

    public static function calcoloImportoInvestimentoPerPlant($plant, $financedQuote)
    {
        $has = SaveToolController::getHasByPlantId($plant["id"]);
        //creazione array delle HAS
        $arrayTOBE = $has["dataToBe"];
        $result = 0;
        for ($i = 0; $i < count($arrayTOBE); $i++){
            $haTOBE = $arrayTOBE[$i];
            $result += CalculateHelper::calcolaImportoInvestimentoPerHA($haTOBE) * $financedQuote /100;
        }
        return $result;
    }

    /**
     * Calcolo Costi/benefici annuali in consumo energetico della ZO
     * sommando i valori degli Cluster che gli appartengono (per HAS ASIS che hanno una lista di cluster ASIS associati)
     *
     * @input ore_acc_piena     ->$cluster[hours_full_light]
     * @input %dimm             ->$cluster[dimmering]
     * @input ore_dimm          ->$cluster[hours_dimmering_light]
     * @input n_apparecchi      ->$cluster[device_num]
     * @input potenza_m_morsett ->$cluster[average_device_power]
     * */

    public static function calcoloConsumoEnergeticoPerHaASIS($ha){

        $clusters = SaveToolController::getClustersByHaId($ha["id"])["clusters"];

        $consumoEnergeticoHa = 0;

        if(count($clusters) > 0){
            //sommatoria per ogni cluster
            for ($i = 0; $i < count($clusters); $i++) {
                $cluster = $clusters[$i];

                $consumoEnergetico = ($cluster["hours_full_light"] + (1 - ($cluster["dimmering"] / 100)) * $cluster["hours_dimmer_light"]) * $cluster["device_num"]
                    * $cluster["average_device_power"];

                $consumoEnergeticoHa += $consumoEnergetico;
            }
        }

        return $consumoEnergeticoHa;
    }

    /**
     * Calcolo Costi/benefici annuali in consumo energetico della ZO TOBE
     * essendo singola non ha bisogno di un calcolo ricorsivo
     *
     * @input ore_acc_piena     ->$cluster[hours_full_light]
     * @input %dimm             ->$cluster[dimmering]
     * @input ore_dimm          ->$cluster[hours_dimmering_light]
     * @input n_apparecchi      ->$cluster[device_num]
     * @input potenza_m_morsett ->$cluster[average_device_power]
     * */

    public static function calcoloConsumoEnergeticoPerHaTOBE($ha){

        $result = 0;
        $resultQuery = SaveToolController::getClustersByHaId_TOBEfeatured($ha["id"]);
        if ($resultQuery["success"] === true) {
            $cluster = $resultQuery["clusters"];
            $result =  ($cluster["hours_full_light"] + (1 - ($cluster["dimmering"] / 100)) * $cluster["hours_dimmer_light"]) * $cluster["device_num"] * $cluster["average_device_power"];
        }
        return $result;

    }


    /**
     * Calcolo Costi/benefici annuali in consumo energetico come Delta tra
     * la sommatoria delle ZO AS-IS e TO-BE
     * il risultato è un DELTA tra due ZO associate e non un aggregato
     *
     * @input $plant
     * @input calcoloConsumoEnergeticoPerHa($has)
     * */
    public static function calcoloDeltaConsumoEnergeticoPerHAS($haASIS, $haTOBE, $result)
    {
        $value = CalculateHelper::calcoloConsumoEnergeticoPerHaASIS($haASIS) - CalculateHelper::calcoloConsumoEnergeticoPerHaTOBE($haTOBE);

        //posiziona l'elaborazione nella cella specifica
        return collect($result)->each(function (Risultato_singolaZO $singolo) use ($value, $haASIS){
            if($singolo->getAsisName() == $haASIS["label_ha"]){
                $singolo->setDeltaEnergyConsumption($value);
                return false;
            }
        });
    }


    /**
     * Calcola costi/benefici annuali in spesa energetica per ZO ASIS aggregando tutti i suoi cluster
     *
     * @input $ha
     * @input $costo_unitario
     * */
    public static function calcoloSpesaEnergeticaPerHaASIS($ha, $costo_unitario){

        if($ha && $costo_unitario){
            //prendo tutti i cluster che appartengono alla zona omogenea con un certo id
            $clusters = SaveToolController::getClustersByHaId($ha["id"])["clusters"];

            $spesaEnergeticaHa = 0;

            for ($i = 0; $i < count($clusters); $i++) {
                $cluster = $clusters[$i];

                //calcolo spesa energetica i-esimo cluster
                $spesaEnergetica = ($cluster["hours_full_light"] + (1 - ($cluster["dimmering"] / 100)) * $cluster["hours_dimmer_light"]) * $cluster["device_num"] * $cluster["average_device_power"] * ((float)$costo_unitario / 1000);

                //somma delle singole spese energetiche in quella generale della zona omogenea
                $spesaEnergeticaHa += $spesaEnergetica;
            }

            return $spesaEnergeticaHa;
        }
        return 0;
    }

    /**
     * Calcola costi/benefici annuali in spesa energetica per ZO TOBE, essendocene una sola non ho bisogno di aggregare tutti i cluster
     *
     * @input $ha
     * @input $costo_unitario
     * */
    public static function calcoloSpesaEnergeticaPerHaTOBE($ha, $costo_unitario){

        if($ha && $costo_unitario){
            $result = 0;
            $resultQuery = SaveToolController::getClustersByHaId_TOBEfeatured($ha["id"]);
            if ($resultQuery["success"] === true) {
                $cluster = $resultQuery["clusters"];
                $result =   ($cluster["hours_full_light"] + (1 - ($cluster["dimmering"] / 100)) * $cluster["hours_dimmer_light"]) * $cluster["device_num"] * $cluster["average_device_power"] * ((float)$costo_unitario / 1000);
            }
            return $result;

        }

        return 0;
    }


    /**
     * Calcolo Costi/benefici annuali in spesa energetica come Delta tra
     * la sommatoria delle ZO AS-IS e TO-BE
     * il risultato è un DELTA tra due ZO associate e non un aggregato
     *
     * @input $plant
     * @input calcoloSpesaEnergeticoPerHa($has)
     * */

    public static function calcoloDeltaSpesaEnergeticaPerHAS($haASIS, $haTOBE, $energyCost, $result)
    {
        $value = CalculateHelper::calcoloSpesaEnergeticaPerHaASIS($haASIS, $energyCost) - CalculateHelper::calcoloSpesaEnergeticaPerHaTOBE($haTOBE, $energyCost);

        return collect($result)->each(function (Risultato_singolaZO $singolo) use ($value, $haASIS){
           if($singolo->getAsisName() == $haASIS["label_ha"]){
               $singolo->setDeltaEnergyExpenditure($value);
               return false;
           }
        });

    }
    /**
     * Calcolo Costi/benefici annuali in spesa energetica come Delta tra
     * la sommatoria delle ZO AS-IS e TO-BE
     * il risultato è UN AGGREGATO di tutte le ZO (e non un array con i singoli delta)
     *
     * @input $plant
     * @input calcoloSpesaEnergeticoPerHa($has)
     * */

    public static function calcoloDeltaSpesaEnergeticaPerImpianto($plant, $investment)
    {
        $data = SaveToolController::getHasByPlantId($plant["id"]);
        $energyCost = (SaveToolController::getEnergyUnitCostForInvestment($investment["id"]))["energy_unit_cost"];

        //calcolo il consumo energetico delle HA AS-IS
        $arrayASIS = $data["dataAsIs"];
        $arrayTOBE = $data["dataToBe"];
        $result = 0;
        for ($i = 0; $i < count($arrayASIS); $i++) {
            //per ogni $haASIS
            $haASIS = $arrayASIS[$i];

            //cerco la HA TOBE associata
            $haTOBE = collect($arrayTOBE)->filter(function ($single) use ($haASIS) {
                return $single["ref_as_is_id_ha"] == $haASIS["id"];
            })->first();

            //prendo tutte le ZO AS-IS, sommatoria CU e calcolo - prendo solo la TO-BE associata e sottraggo
            $value = CalculateHelper::calcoloSpesaEnergeticaPerHaASIS($haASIS, $energyCost) - CalculateHelper::calcoloSpesaEnergeticaPerHaTOBE($haTOBE, $energyCost);

            //sommo per aggregare i risultati
            $result += $value;
        }

        //restituisco il risultato
        return $result;
    }

    public static function calcoloIncentiviStatali($tepKwh, $tepValue, $risultatoSingolaZO){
        if($tepKwh && $tepValue && $risultatoSingolaZO){
            $risultatoSingolaZO->setIncentiveRevenue(
                ($risultatoSingolaZO->getDeltaEnergyConsumption() > 0)?
                    ($risultatoSingolaZO->getDeltaEnergyConsumption() / $tepKwh) * $tepValue : 0);
        }
    }

    public static function calcoloCostiManutenzione($durationAmortization, $haASIS, $haTOBE, $risultatoSingolaZO){
        if($durationAmortization && $haASIS && $haTOBE && $risultatoSingolaZO){
            //calcolo flussi e totale costo manutenzione ASIS e TOBE
            for($j = 1; $j <= $durationAmortization; $j++) {
                //costo
                $result_asis_maintenance_cost[$j] = CalculateHelper::calcolaCostiManutezionePerHA($haASIS) * (($j % $haASIS["lamp_maintenance_interval"] == 0) ? 1 : 0);
                $result_tobe_lamp_cost[$j] = CalculateHelper::calcolaCostiManutezionePerHA($haTOBE) * (($j % $haTOBE["lamp_maintenance_interval"] == 0) ? 1 : 0);
                $result_tobe_infrastructure_cost[$j] = CalculateHelper::calcolaCostoManutenzioneInfrastrutturaPerHA($haTOBE) * (($j % $haTOBE["lamp_maintenance_interval"] == 0) ? 1 : 0);
            }
            $risultatoSingolaZO->asis_maintenance_cost = array_sum($result_asis_maintenance_cost);
            $risultatoSingolaZO->tobe_maintenance_cost = array_sum($result_tobe_infrastructure_cost) + array_sum($result_tobe_lamp_cost);

            $result["asis_maintenance_cost"] = $result_asis_maintenance_cost;
            $result["tobe_infrastructure_cost"] = $result_tobe_infrastructure_cost;
            $result["tobe_lamp_cost"] = $result_tobe_lamp_cost;
            return $result;

        }

        return [];
    }


    /**
     * calcola il totale lampade per HA in base ai cluster
     *
     * @input $ha
     * */

    public static function calcolaTotaleLampadePerHA($ha) {
        $clusters = SaveToolController::getClustersByHaId($ha["id"])["clusters"];

        $nLampadeTot = 0;

        for ($i = 0; $i < count($clusters); $i++) {
            $cluster = $clusters[$i];
            $nLampadeTot += $cluster["lamp_num"];
        }

        return $nLampadeTot;
    }

    public static function calcolaCostiManutezionePerHA($ha){
        return ($ha["lamp_cost"] + $ha["lamp_disposal"]) * CalculateHelper::calcolaTotaleLampadePerHA($ha);
    }


    public static function calcolaCostoManutenzioneInfrastrutturaPerHA($ha){
        return $ha["infrastructure_maintenance_cost"] * CalculateHelper::calcolaTotaleLampadePerHA($ha);
    }


    public static function calcoloVANperImpianto($cashFlow, $wacc, $round = 3): float
    {

        $wacc_absolute = floatval($wacc / 100);

        $result = 0;

        for ($i = 0; $i < count($cashFlow); $i++) {
            $result += ($cashFlow[$i] / ((1 + $wacc_absolute)**($i)));
        }
        return round($result, $round);
    }

    public static function calcoloTIRperImpianto($cashFlow, $investment_amount): ?float
    {
        $maxIterations = 100;
        $tolerance = 0.00001;
        $guess = 0.1;

        $count = count($cashFlow);

        $cashFlow[0] = $investment_amount;

        $positive = false;
        $negative = false;
        for ($i = 0; $i < $count; $i++) {
            if ($cashFlow[$i] > 0) {
                $positive = true;
            } else {
                $negative = true;
            }
        }

        if (!$positive || !$negative) {
            return null;
        }

        $guess = ($cashFlow == 0) ? 0.1 : $guess;

        for ($i = 0; $i < $maxIterations; $i++) {
            $npv = 0;
            $dnpv = 0.00;

            for ($j = 0; $j < $count; $j++) {
                $npv += $cashFlow[$j] / pow(1 + $guess, $j);
                $dnpv -= $j * $cashFlow[$j] / pow(1 + $guess, $j + 1);
            }

            if($dnpv != 0)
                $newGuess = $guess - ($npv / $dnpv);
            else
                return 0;

            if (abs($newGuess - $guess) < $tolerance) {
                return $newGuess;
            }

            $guess = $newGuess;
        }

        return $guess;
    }

    public static function calcoloPayBackTime($flussoDiCassaTotale, $durata_ammortamento){
        $payback_time = 0;
        $flusso_cumulativo[0] = $flussoDiCassaTotale[0];

        /*
         * calcolo flusso cumulativo
         */
        for ($i = 1; $i < $durata_ammortamento + 1; $i++) {
            $flusso_cumulativo[$i] = $flussoDiCassaTotale[$i] + $flusso_cumulativo[$i - 1];
        }

        /*
         * ultimo flusso di cassa cumulativo negativo
         */
        for ($i = $durata_ammortamento + 1; $i > 0; $i--) {
            if (isset($flusso_cumulativo[$i])) {
                if ($flusso_cumulativo[$i] < 0) {
                    $payback_time = $i;
                    break;
                }
            }
        }

        if ($payback_time > 0 && isset($flussoDiCassaTotale[$payback_time + 1]) && (int)$flussoDiCassaTotale[$payback_time + 1] !== 0) {
            $payback_time += (abs($flusso_cumulativo[$payback_time]) / $flussoDiCassaTotale[$payback_time + 1]);
        } else
        {$payback_time = 0;}

        return ($payback_time > 0)? $payback_time : 0;
    }

    public static function calcoloCanoneMinimo($importoInvestimento, $investment, $feeDuration, $taxes, $financedQuote){
        if(!$feeDuration)
            $feeDuration = $investment["project_duration"];
        if(!$taxes)
            $taxes = $investment["taxes"];
        if(!$financedQuote)
            $financedQuote = $investment["share_esco"];

        $wacc_absolute = floatval($investment["wacc"] / 100);
        $investment_ESCO = $importoInvestimento * ($financedQuote/100);
        $canoneIniziale = ($investment_ESCO) / ((1- ((1+$wacc_absolute)**(-$feeDuration))) /$wacc_absolute);

        $ammortamento = $investment_ESCO / $feeDuration;
        $result = ($canoneIniziale - $ammortamento * $taxes / 100) / (1- $taxes / 100);
        if($result > 0)
            return $result;
        else
            return 0;
    }

    public static function calcoloCanoneMassimo($plant, $importoInvestimento, $investment, $feeDuration, $financedQuote){
        if(!$feeDuration)
            $feeDuration = $investment["project_duration"];
        if(!$financedQuote)
            $financedQuote = $investment["share_esco"];

        $wacc_absolute = floatval($investment["wacc"] / 100);
        $investimentoIniziale_comune = $importoInvestimento * ($financedQuote/ 100);
        $ammortamento_comune = $investimentoIniziale_comune / ((1- ((1+$wacc_absolute)**(-$feeDuration))) /$wacc_absolute);
        $result = self::calcoloDeltaSpesaEnergeticaPerImpianto($plant, $investment) - ($ammortamento_comune - $investment["mortgage_installment"]);
        if($result > 0)
            return $result;
        else
            return 0;
    }

    public static function calcoloFlussiDiCassaPerHA($plant, $investment, $durationAmortization_override, $energy_unit_cost_override) {
        $has = SaveToolController::getHasByPlantId($plant["id"]);
        //creazione array delle HAS
        $arrayASIS = $has["dataAsIs"];
        $arrayTOBE = $has["dataToBe"];


        if($energy_unit_cost_override)
            $energyCost = $energy_unit_cost_override;
        else
            //prendo il costo unitario dell'energia per l'investimento (inserito dall'utente
            $energyCost = (SaveToolController::getEnergyUnitCostForInvestment($investment["id"]))["energy_unit_cost"];

        for ($i = 0; $i < count($arrayASIS); $i++){
            //inizializzazione oggetto di output
            $result[$i] = new Risultato_singolaZO();

            //singola HA ASIS
            $haASIS = $arrayASIS[$i];
            $result[$i]->setAsisName($haASIS["label_ha"]);

            //getting TOBE associata
            $haTOBE = collect($arrayTOBE)->filter(function ($single) use ($haASIS) {
                return $single["ref_as_is_id_ha"] == $haASIS["id"];
            })->first();

            $result[$i]->setTobeName($haTOBE["label_ha"]);
            //fine inizializzazione oggetto di output

            //inizio calcolo
            //calcolo costo investimento
            $result[$i]->setInvestmentAmount(CalculateHelper::calcolaImportoInvestimentoPerHA($haTOBE) * $investment["share_municipality"] /100);

            //Calcola costi/benefici annuali in consumo energetico
            CalculateHelper::calcoloDeltaConsumoEnergeticoPerHAS($haASIS, $haTOBE, $result);
            //Calcola costi/benefici annuali in spesa energetica
            CalculateHelper::calcoloDeltaSpesaEnergeticaPerHAS($haASIS, $haTOBE, $energyCost, $result);

            //Calcola incentivi statali
            CalculateHelper::calcoloIncentiviStatali($investment["tep_kwh"], $investment["tep_value"] , $result[$i]);

            //scelta della durata dell'ammortamento
            ($durationAmortization_override) ? $durationAmortization = $durationAmortization_override : $durationAmortization = $investment["duration_amortization"];

            //Calcola costi manutenzione
            $costiManutenzione = CalculateHelper::calcoloCostiManutenzione($durationAmortization, $haASIS, $haTOBE, $result[$i]);

            $result_asis_maintenance_cost = $costiManutenzione["asis_maintenance_cost"];
            $result_tobe_infrastructure_cost = $costiManutenzione["tobe_infrastructure_cost"];
            $result_tobe_lamp_cost = $costiManutenzione["tobe_lamp_cost"];

            //Calcola flussi di cassa annuali
            $result[$i]->cash_flow[0] = - $result[$i]->getInvestmentAmount();
            for($j = 1; $j <= $durationAmortization; $j++) {
                //costo
                $result[$i]->cash_flow[$j] = + $result[$i]->getDeltaEnergyExpenditure()
                    + $result_asis_maintenance_cost[$j] - $investment["mortgage_installment"]
                    - $investment["fee_esco"] - $result_tobe_lamp_cost[$j]
                    - $result_tobe_infrastructure_cost[$j] - $investment["management_cost"];
            }

            for($j = 1; $j <= $investment["incentives_duration"]; $j++){
                $result[$i]->cash_flow[$j] += $result[$i]->getIncentiveRevenue();
            }
        }

        return $result;
    }

    public static function calcoloFlussiDiCassaPerPlant($risultatoSingolaZO){

        //calcolo totali
        //iniziializzazione array
        for($j = 0; $j<count($risultatoSingolaZO[0]->cash_flow); $j++) {
            $cashFlowTotale[$j] = 0;
        }

        //calcolo cashflow totale per calcolo VAN, TIR e Payback
        for($i = 0; $i<count($risultatoSingolaZO); $i++){
            for($j = 0; $j<count($risultatoSingolaZO[$i]->cash_flow); $j++){
                $cashFlowTotale[$j] += $risultatoSingolaZO[$i]->cash_flow[$j];
            }
        }

        return $cashFlowTotale;
    }

    public static function calcolaImportoInvestimentoPerPlant($risultatiSingolaZO){
        $result = 0;
        for($i = 0; $i<count($risultatiSingolaZO); $i++){
            $result += $risultatiSingolaZO[$i]->getInvestmentAmount();
        }
        return $result;
    }

    public static function calcolaCostiManutezioneASISPerPlant($risultatiSingolaZO){
        $result = 0;
        for($i = 0; $i<count($risultatiSingolaZO); $i++){
            $result += $risultatiSingolaZO[$i]->getAsisMaintenanceCost();
        }
        return $result;
    }

    public static function calcolaCostiManutezioneTOBEPerPlant($risultatiSingolaZO){
        $result = 0;
        for($i = 0; $i<count($risultatiSingolaZO); $i++){
            $result += $risultatiSingolaZO[$i]->getTobeMaintenanceCost();
        }
        return $result;
    }

    public static function calcolaContributoIncentiviPerPlant($risultatiSingolaZO){
        $result = 0;
        for($i = 0; $i<count($risultatiSingolaZO); $i++){
            $result += $risultatiSingolaZO[$i]->getIncentiveRevenue();
        }
        return $result;
    }

    public static function calcolaDeltaSpesaEnergeticaPerPlant($risultatiSingolaZO){
        $result = 0;
        for($i = 0; $i<count($risultatiSingolaZO); $i++){
            $result += $risultatiSingolaZO[$i]->getDeltaEnergyExpenditure();
        }
        return $result;
    }

    public static function calcolaDeltaConsumoEnergeticoPerPlant($risultatiSingolaZO){
        $result = 0;
        for($i = 0; $i<count($risultatiSingolaZO); $i++){
            $result += $risultatiSingolaZO[$i]->getDeltaEnergyConsumption();
        }
        return $result;
    }

    public static function calcoloPilota($plant, $investment){
        $result["municipality"] = $plant["label_plant"];
        $result["plants"] = self::calcoloFlussiDiCassaPerHA($plant, $investment, null, null);

        //calcolo totali
        $cashFlowTotale = self::calcoloFlussiDiCassaPerPlant($result["plants"]);
        $result["total"]["cash_flow"] = $cashFlowTotale;
        $result["total"]["investment_amount"] = self::calcolaImportoInvestimentoPerPlant($result["plants"]);
        $result["total"]["asis_maintenance_cost"] = self::calcolaCostiManutezioneASISPerPlant($result["plants"]);
        $result["total"]["tobe_maintenance_cost"] = self::calcolaCostiManutezioneTOBEPerPlant($result["plants"]);
        $result["total"]["incentive_revenue"] = self::calcolaContributoIncentiviPerPlant($result["plants"]);
        $result["total"]["delta_energy_expenditure"] = self::calcolaDeltaSpesaEnergeticaPerPlant($result["plants"]);
        $result["total"]["delta_energy_consumption"] = self::calcolaDeltaConsumoEnergeticoPerPlant($result["plants"]);

        //calcolo sommatorie parametri dell'investimento
        //Calcola VAN e TIR
        $result["financement"]["van"] = self::calcoloVANperImpianto($cashFlowTotale, $investment["wacc"]);
        $result["financement"]["tir"] = self::calcoloTIRperImpianto($cashFlowTotale, $result["total"]["investment_amount"]);

        //Calcola Payback Time
        $result["financement"]["payback_time"] = self::calcoloPayBackTime($cashFlowTotale, $investment["duration_amortization"]);
        //Calcola Canone Minimo
        $result["financement"]["fee_min"] = self::calcoloCanoneMinimo($result["total"]["investment_amount"], $investment, null, null, null);
        //Calcola Canone Massimo
        $result["financement"]["fee_max"] = self::calcoloCanoneMassimo($plant, $result["total"]["investment_amount"], $investment, null, null);

        return $result;
    }

}
