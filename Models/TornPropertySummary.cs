namespace TornPropertyManager.Models;

public class TornPropertySummary
{
    public int PropertyId { get; set; }

    public string PropertyName { get; set; } = "";
    public string Status { get; set; } = "";
    public int Happy { get; set; }

    public int Upkeep { get; set; }
    public int StaffCost { get; set; }
    public int MarketPrice { get; set; }

    public bool IsRentedOut { get; set; }

    public int? RentedToUserId { get; set; }
    public int? DaysLeft { get; set; }
    public int? RentTotalCost { get; set; }
    public int? RentCostPerDay { get; set; }
}
